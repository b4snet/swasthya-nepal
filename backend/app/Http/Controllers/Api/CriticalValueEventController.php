<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CriticalValueEvent;
use App\Models\Staff;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 7 — Laboratory critical-value escalation (PRODUCT_REQUIREMENTS
 * §6.8 workflow 6, CLINICAL_SAFETY §7). The result enterer flags a critical
 * value at entry, which triggers a critical_value_event targeted at the
 * ordering clinician. The clinician ACKNOWLEDGES it (who/when recorded); if it
 * stays unacknowledged a supervisor ESCALATES it — fail loudly, never silently
 * (MASTER_RULES §11.3).
 *
 *   triggered → acknowledged   (target clinician, lab:acknowledge)
 *   triggered → escalated      (supervisor, lab:escalate, never the target)
 *   escalated → acknowledged   (target clinician — escalation stays loud
 *                               until a human closes it)
 *
 * Both transitions are compare-and-swap on (status, lock_version): a
 * concurrent actor affects 0 rows and gets 409 CONFLICT.
 */
final class CriticalValueEventController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /critical-value-events — the escalation queue for the facility:
     * every in-scope event, oldest first (the loudest sits at the top).
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $events = CriticalValueEvent::query()
            ->where('tenant_id', $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->with('item.test:id,name,sample_type')
            ->orderBy('detected_at')
            ->get()
            ->map(fn (CriticalValueEvent $event): array => $this->present($event))
            ->values();

        return Envelope::success(data: $events, request: $request);
    }

    /**
     * POST /critical-value-events/{criticalValueEvent}/acknowledge — the
     * ordering clinician closes the loop. Only the TARGET clinician may
     * acknowledge their own critical value; acknowledged is terminal.
     */
    public function acknowledge(Request $request, CriticalValueEvent $criticalValueEvent): JsonResponse
    {
        AccessCheck::scoped($criticalValueEvent, write: true);

        if ($criticalValueEvent->status === CriticalValueEvent::STATUS_ACKNOWLEDGED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This critical value has already been acknowledged.', 409);
        }

        $context = TenantContext::current();
        $clinician = $this->currentTarget($criticalValueEvent, $context);

        $updated = DB::table('critical_value_events')
            ->where('id', $criticalValueEvent->getKey())
            ->whereIn('status', [CriticalValueEvent::STATUS_TRIGGERED, CriticalValueEvent::STATUS_ESCALATED])
            ->where('lock_version', $criticalValueEvent->lock_version)
            ->update([
                'status' => CriticalValueEvent::STATUS_ACKNOWLEDGED,
                'acknowledged_by_staff_id' => $clinician->getKey(),
                'acknowledged_at' => now(),
                'lock_version' => $criticalValueEvent->lock_version + 1,
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This critical value was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'critical_value.acknowledged',
            'critical_value_event',
            $criticalValueEvent->getKey(),
            ['encounterId' => $criticalValueEvent->encounter_id, 'itemId' => $criticalValueEvent->lab_order_item_id, 'acknowledgedByStaffId' => $clinician->getKey()],
            $request,
        );

        return Envelope::success(data: $this->present($criticalValueEvent->fresh('item.test:id,name,sample_type')), request: $request);
    }

    /**
     * POST /critical-value-events/{criticalValueEvent}/escalate — a
     * supervisor escalates an unacknowledged critical value. The escalator
     * must NOT be the target clinician (the person who should have
     * acknowledged). Escalation keeps the event loud; acknowledgment after
     * escalation still closes it.
     */
    public function escalate(Request $request, CriticalValueEvent $criticalValueEvent): JsonResponse
    {
        AccessCheck::scoped($criticalValueEvent, write: true);

        if ($criticalValueEvent->status !== CriticalValueEvent::STATUS_TRIGGERED) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Only a triggered critical value can be escalated (current status: '.$criticalValueEvent->status.').',
                409,
            );
        }

        $context = TenantContext::current();
        $escalator = $this->currentSupervisor($criticalValueEvent, $context);

        if ($escalator->getKey() === $criticalValueEvent->target_staff_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'The ordering clinician must acknowledge the critical value, not escalate it.', 403);
        }

        $updated = DB::table('critical_value_events')
            ->where('id', $criticalValueEvent->getKey())
            ->where('status', CriticalValueEvent::STATUS_TRIGGERED)
            ->where('lock_version', $criticalValueEvent->lock_version)
            ->update([
                'status' => CriticalValueEvent::STATUS_ESCALATED,
                'escalated_by_staff_id' => $escalator->getKey(),
                'escalated_at' => now(),
                'lock_version' => $criticalValueEvent->lock_version + 1,
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This critical value was concurrently modified; refresh and retry.', 409);
        }

        $this->audit->record(
            'critical_value.escalated',
            'critical_value_event',
            $criticalValueEvent->getKey(),
            ['encounterId' => $criticalValueEvent->encounter_id, 'itemId' => $criticalValueEvent->lab_order_item_id, 'escalatedByStaffId' => $escalator->getKey()],
            $request,
        );

        return Envelope::success(data: $this->present($criticalValueEvent->fresh('item.test:id,name,sample_type')), request: $request);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The authenticated user's staff profile, which must be the event's
     * target clinician (the ordering provider of the flagged item's order).
     */
    private function currentTarget(CriticalValueEvent $criticalValueEvent, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $criticalValueEvent->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->getKey() !== $criticalValueEvent->target_staff_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'Only the ordering clinician can acknowledge this critical value.', 403);
        }

        return $staff;
    }

    /**
     * The authenticated user's staff profile in the event's tenant. The
     * escalate route gate (lab:escalate) already limited the caller; this
     * just resolves the staff record.
     */
    private function currentSupervisor(CriticalValueEvent $criticalValueEvent, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $criticalValueEvent->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'No active staff profile for this user in the event\'s tenant.', 403);
        }

        return $staff;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CriticalValueEvent $event): array
    {
        return [
            'id' => $event->getKey(),
            'facilityId' => $event->facility_id,
            'patientId' => $event->patient_id,
            'encounterId' => $event->encounter_id,
            'itemId' => $event->lab_order_item_id,
            'testId' => $event->item?->lab_test_id,
            'testName' => $event->item?->test?->name,
            'resultValue' => $event->item?->result_value,
            'resultUnit' => $event->item?->result_unit,
            'targetStaffId' => $event->target_staff_id,
            'status' => $event->status,
            'detectedByStaffId' => $event->detected_by_staff_id,
            'detectedAt' => $event->detected_at?->toIso8601String(),
            'escalatedByStaffId' => $event->escalated_by_staff_id,
            'escalatedAt' => $event->escalated_at?->toIso8601String(),
            'acknowledgedByStaffId' => $event->acknowledged_by_staff_id,
            'acknowledgedAt' => $event->acknowledged_at?->toIso8601String(),
            'lockVersion' => $event->lock_version,
        ];
    }
}
