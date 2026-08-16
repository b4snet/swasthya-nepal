<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ot\AddTeamMemberRequest;
use App\Http\Requests\Ot\AdmitToRecoveryRequest;
use App\Http\Requests\Ot\RecordSurgicalEventRequest;
use App\Http\Requests\Ot\ScheduleProcedureRequest;
use App\Http\Requests\Ot\StartAnesthesiaRequest;
use App\Http\Requests\Ot\StartProcedureRequest;
use App\Http\Requests\Ot\StoreProcedureRequest;
use App\Http\Requests\Ot\StoreTheatreRequest;
use App\Models\ChecklistItem;
use App\Models\Procedure;
use App\Models\ProcedureRequest;
use App\Models\RecoveryRecord;
use App\Models\Theatre;
use App\Services\OtIcuBloodBankService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 20 — Operating Theatre (PRODUCT_REQUIREMENTS §6.10,
 * DATABASE.md §3.48): theatre scheduling with conflict detection,
 * procedure records, team log, anesthesia, time-stamped events, structured
 * safety checklists, and PACU recovery. Case closure (ot:close) requires
 * checklist compliance. Surgical records are high-value medico-legal
 * documents — audit payloads carry facts and ids only, never procedure
 * names or notes.
 */
final class OtController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtIcuBloodBankService $ot,
    ) {}

    /**
     * GET theatres — the facility's operating theatre catalog.
     */
    public function theatres(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $theatres = Theatre::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('name')
            ->get()
            ->map(fn (Theatre $t): array => [
                'id' => $t->getKey(),
                'code' => $t->code,
                'name' => $t->name,
                'status' => $t->status,
            ])
            ->values();

        return Envelope::success(data: $theatres, request: $request);
    }

    /**
     * POST theatres — create an operating theatre.
     */
    public function storeTheatre(StoreTheatreRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $theatre = $this->ot->createTheatre(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('code'),
            $request->validated('name'),
            $request->validated('status', Theatre::STATUS_ACTIVE),
            $this->currentStaffId($context),
        );

        $this->audit->record('theatre.created', 'theatre', $theatre->getKey(), [
            'code' => $theatre->code,
        ], $request);

        return Envelope::success(data: [
            'id' => $theatre->getKey(),
            'code' => $theatre->code,
            'name' => $theatre->name,
            'status' => $theatre->status,
        ], status: 201, request: $request);
    }

    /**
     * GET procedure-requests — the facility's procedure requests.
     */
    public function procedureRequests(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $requests = ProcedureRequest::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ProcedureRequest $r): array => self::presentRequest($r))
            ->values();

        return Envelope::success(data: $requests, request: $request);
    }

    /**
     * POST procedure-requests — request a surgical procedure.
     */
    public function storeProcedureRequest(StoreProcedureRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $requestedBy = $this->currentStaffId($context);

        if ($requestedBy === null) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'A linked staff profile is required to request a procedure.', 403);
        }

        $procedureRequest = $this->ot->createProcedureRequest(
            (string) $context->tenantId(),
            (string) $context->facilityId(),
            $request->validated('patientId'),
            $request->validated('encounterId'),
            $requestedBy,
            $request->validated('procedureName'),
            $request->validated('priority', ProcedureRequest::PRIORITY_ROUTINE),
            $requestedBy,
        );

        $this->audit->record('procedure_request.created', 'procedure_request', $procedureRequest->getKey(), [
            'patientId' => $procedureRequest->patient_id,
            'priority' => $procedureRequest->priority,
        ], $request);

        return Envelope::success(data: self::presentRequest($procedureRequest), status: 201, request: $request);
    }

    /**
     * POST procedure-requests/{request}/schedule — assign theatre/date/time
     * with conflict detection (two cases on one theatre is refused).
     */
    public function scheduleProcedureRequest(ScheduleProcedureRequest $request, ProcedureRequest $procedureRequest): JsonResponse
    {
        AccessCheck::scoped($procedureRequest, write: true);
        $context = TenantContext::current();

        $updated = $this->ot->scheduleProcedureRequest(
            $procedureRequest,
            $request->validated('theatreId'),
            $this->parseDate($request->validated('scheduledAt')),
            (int) $request->validated('durationMinutes'),
            $this->currentStaffId($context),
        );

        $this->audit->record('procedure_request.scheduled', 'procedure_request', $updated->getKey(), [
            'theatreId' => $updated->theatre_id,
        ], $request);

        return Envelope::success(data: self::presentRequest($updated), request: $request);
    }

    /**
     * POST procedure-requests/{request}/cancel — cancel a scheduled case.
     */
    public function cancelProcedureRequest(Request $httpRequest, ProcedureRequest $procedureRequest): JsonResponse
    {
        AccessCheck::scoped($procedureRequest, write: true);
        $context = TenantContext::current();

        $updated = $this->ot->cancelProcedureRequest($procedureRequest, $this->currentStaffId($context));

        $this->audit->record('procedure_request.cancelled', 'procedure_request', $updated->getKey(), [], $httpRequest);

        return Envelope::success(data: self::presentRequest($updated), request: $httpRequest);
    }

    /**
     * POST procedure-requests/{request}/start — start the case: create the
     * procedure record and snapshot the safety checklist.
     */
    public function startProcedure(StartProcedureRequest $request, ProcedureRequest $procedureRequest): JsonResponse
    {
        AccessCheck::scoped($procedureRequest, write: true);
        $context = TenantContext::current();

        [$procedure, $items] = $this->ot->startProcedure(
            $procedureRequest,
            $request->validated('checklistTemplateId'),
            $request->validated('surgeonStaffId'),
            $this->currentStaffId($context),
        );

        $this->audit->record('procedure.started', 'procedure', $procedure->getKey(), [
            'patientId' => $procedure->patient_id,
            'theatreId' => $procedure->theatre_id,
            'checklistSteps' => count($items),
        ], $request);

        return Envelope::success(data: [
            'id' => $procedure->getKey(),
            'status' => $procedure->status,
            'checklist' => collect($items)->map(fn (ChecklistItem $item): array => self::presentChecklistItem($item))->values(),
        ], status: 201, request: $request);
    }

    /**
     * POST procedures/{procedure}/team — log a surgical team member.
     */
    public function addTeamMember(AddTeamMemberRequest $request, Procedure $procedure): JsonResponse
    {
        AccessCheck::scoped($procedure, write: true);
        $context = TenantContext::current();

        $member = $this->ot->addTeamMember(
            $procedure,
            $request->validated('staffId'),
            $request->validated('role'),
            $request->validated('timeIn') !== null ? $this->parseDate($request->validated('timeIn')) : null,
            $this->currentStaffId($context),
        );

        $this->audit->record('procedure.team_member_added', 'procedure', $procedure->getKey(), [
            'role' => $member->role,
        ], $request);

        return Envelope::success(data: [
            'id' => $member->getKey(),
            'staffId' => $member->staff_id,
            'role' => $member->role,
            'timeIn' => $member->time_in?->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * POST procedures/{procedure}/anesthesia — record the anesthesia record.
     */
    public function startAnesthesia(StartAnesthesiaRequest $request, Procedure $procedure): JsonResponse
    {
        AccessCheck::scoped($procedure, write: true);
        $context = TenantContext::current();

        $record = $this->ot->startAnesthesia(
            $procedure,
            $request->validated('anesthetistStaffId'),
            $request->validated('anesthesiaType'),
            $request->validated('startedAt') !== null ? $this->parseDate($request->validated('startedAt')) : null,
            $request->validated('notes'),
            $this->currentStaffId($context),
        );

        $this->audit->record('procedure.anesthesia_started', 'procedure', $procedure->getKey(), [
            'anesthesiaType' => $record->anesthesia_type,
        ], $request);

        return Envelope::success(data: [
            'id' => $record->getKey(),
            'procedureId' => $record->procedure_id,
            'anesthesiaType' => $record->anesthesia_type,
            'startedAt' => $record->started_at?->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * POST procedures/{procedure}/events — record a time-stamped
     * intra-operative event.
     */
    public function recordSurgicalEvent(RecordSurgicalEventRequest $request, Procedure $procedure): JsonResponse
    {
        AccessCheck::scoped($procedure, write: true);
        $context = TenantContext::current();

        $event = $this->ot->recordSurgicalEvent(
            $procedure,
            $request->validated('eventType'),
            $request->validated('occurredAt') !== null ? $this->parseDate($request->validated('occurredAt')) : null,
            $request->validated('staffId'),
            $request->validated('notes'),
            $this->currentStaffId($context),
        );

        $this->audit->record('procedure.event_recorded', 'procedure', $procedure->getKey(), [
            'eventType' => $event->event_type,
        ], $request);

        return Envelope::success(data: [
            'id' => $event->getKey(),
            'eventType' => $event->event_type,
            'occurredAt' => $event->occurred_at->toIso8601String(),
        ], status: 201, request: $request);
    }

    /**
     * POST procedures/{procedure}/checklist/{item}/complete — complete one
     * safety-checklist step (records WHO and WHEN).
     */
    public function completeChecklistItem(Request $httpRequest, Procedure $procedure, ChecklistItem $item): JsonResponse
    {
        AccessCheck::scoped($procedure, write: true);
        $context = TenantContext::current();

        if ($item->procedure_id !== $procedure->getKey()) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Checklist item not found for this procedure.', 404);
        }

        $completed = $this->ot->completeChecklistItem($item, $this->currentStaffId($context));

        $this->audit->record('procedure.checklist_completed', 'procedure', $procedure->getKey(), [
            'stepKey' => $completed->step_key,
            'sequence' => $completed->sequence,
        ], $httpRequest);

        return Envelope::success(data: self::presentChecklistItem($completed), request: $httpRequest);
    }

    /**
     * POST procedures/{procedure}/close — close the case (ot:close). The
     * surgical safety checklist must be fully completed — compliance is the
     * gate.
     */
    public function closeProcedure(Request $httpRequest, Procedure $procedure): JsonResponse
    {
        AccessCheck::scoped($procedure, write: true);
        $context = TenantContext::current();

        $closed = $this->ot->closeProcedure($procedure, $this->currentStaffId($context));

        $this->audit->record('procedure.closed', 'procedure', $closed->getKey(), [
            'patientId' => $closed->patient_id,
        ], $httpRequest);

        return Envelope::success(data: [
            'id' => $closed->getKey(),
            'status' => $closed->status,
            'endedAt' => $closed->ended_at?->toIso8601String(),
        ], request: $httpRequest);
    }

    /**
     * POST procedures/{procedure}/recovery — admit to PACU recovery.
     */
    public function admitToRecovery(AdmitToRecoveryRequest $request, Procedure $procedure): JsonResponse
    {
        AccessCheck::scoped($procedure, write: true);
        $context = TenantContext::current();

        $recovery = $this->ot->admitToRecovery(
            $procedure,
            (string) $this->currentStaffId($context),
            $request->validated('observations', []),
            $request->validated('admittedAt') !== null ? $this->parseDate($request->validated('admittedAt')) : null,
            $this->currentStaffId($context),
        );

        $this->audit->record('procedure.recovery_admitted', 'procedure', $procedure->getKey(), [], $request);

        return Envelope::success(data: self::presentRecovery($recovery), status: 201, request: $request);
    }

    /**
     * POST recovery/{recoveryRecord}/discharge — discharge from recovery.
     */
    public function dischargeRecovery(Request $httpRequest, RecoveryRecord $recoveryRecord): JsonResponse
    {
        AccessCheck::scoped($recoveryRecord, write: true);
        $context = TenantContext::current();

        $discharged = $this->ot->dischargeRecovery(
            $recoveryRecord,
            (string) $this->currentStaffId($context),
        );

        $this->audit->record('recovery_record.discharged', 'recovery_record', $discharged->getKey(), [], $httpRequest);

        return Envelope::success(data: self::presentRecovery($discharged), request: $httpRequest);
    }

    /**
     * GET procedures/{procedure} — the full procedure record (team, events,
     * checklist, recovery).
     */
    public function showProcedure(Request $request, Procedure $procedure): JsonResponse
    {
        AccessCheck::scoped($procedure, write: false);
        $team = DB::table('surgical_team_members')
            ->where('tenant_id', $procedure->tenant_id)
            ->where('procedure_id', $procedure->getKey())
            ->orderBy('time_in')
            ->get()
            ->map(fn ($m): array => ['id' => $m->id, 'staffId' => $m->staff_id, 'role' => $m->role, 'timeIn' => $m->time_in])
            ->values();

        $events = DB::table('surgical_events')
            ->where('tenant_id', $procedure->tenant_id)
            ->where('procedure_id', $procedure->getKey())
            ->orderBy('occurred_at')
            ->get()
            ->map(fn ($e): array => ['id' => $e->id, 'eventType' => $e->event_type, 'occurredAt' => $e->occurred_at])
            ->values();

        $checklist = $procedure->checklistItems()
            ->orderBy('sequence')
            ->get()
            ->map(fn (ChecklistItem $item): array => self::presentChecklistItem($item))
            ->values();

        $recovery = RecoveryRecord::query()
            ->where('tenant_id', $procedure->tenant_id)
            ->where('procedure_id', $procedure->getKey())
            ->first();

        return Envelope::success(data: [
            'id' => $procedure->getKey(),
            'patientId' => $procedure->patient_id,
            'theatreId' => $procedure->theatre_id,
            'status' => $procedure->status,
            'startedAt' => $procedure->started_at?->toIso8601String(),
            'endedAt' => $procedure->ended_at?->toIso8601String(),
            'team' => $team,
            'events' => $events,
            'checklist' => $checklist,
            'recovery' => $recovery !== null ? self::presentRecovery($recovery) : null,
        ], request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRequest(ProcedureRequest $request): array
    {
        return [
            'id' => $request->getKey(),
            'patientId' => $request->patient_id,
            'procedureName' => $request->procedure_name,
            'priority' => $request->priority,
            'status' => $request->status,
            'theatreId' => $request->theatre_id,
            'scheduledAt' => $request->scheduled_at?->toIso8601String(),
            'scheduledDurationMinutes' => $request->scheduled_duration_minutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentChecklistItem(ChecklistItem $item): array
    {
        return [
            'id' => $item->getKey(),
            'stepKey' => $item->step_key,
            'stepLabel' => $item->step_label,
            'sequence' => $item->sequence,
            'category' => $item->category,
            'completedAt' => $item->completed_at?->toIso8601String(),
            'completedByStaffId' => $item->completed_by_staff_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRecovery(RecoveryRecord $record): array
    {
        return [
            'id' => $record->getKey(),
            'procedureId' => $record->procedure_id,
            'status' => $record->status,
            'admittedAt' => $record->admitted_at?->toIso8601String(),
            'dischargedAt' => $record->discharged_at?->toIso8601String(),
        ];
    }

    private function currentStaffId(TenantContext $context): ?string
    {
        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }

    private function parseDate(mixed $value): CarbonInterface
    {
        return CarbonImmutable::parse($value);
    }
}
