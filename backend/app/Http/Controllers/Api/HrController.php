<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hr\DecideLeaveRequest;
use App\Http\Requests\Hr\GeneratePayrollExportRequest;
use App\Http\Requests\Hr\RequestCorrectionRequest;
use App\Http\Requests\Hr\StoreAttendanceRequest;
use App\Http\Requests\Hr\StoreLeaveRequest;
use App\Http\Requests\Hr\StoreLeaveTypeRequest;
use App\Http\Requests\Hr\StorePositionRequest;
use App\Http\Requests\Hr\StoreRosterRequest;
use App\Http\Requests\Hr\StoreShiftTemplateRequest;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollExport;
use App\Models\Position;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\Staff;
use App\Services\HrAssetsService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 19 — HR (PRODUCT_REQUIREMENTS §6.17, DATABASE.md §3.45):
 * positions, shift templates, rosters (conflict detection), attendance with
 * approved corrections, leave with balance tracking, and audited
 * payroll-ready exports.
 *
 * Staff personal data is protected to the same standard as patient data:
 * audit payloads carry facts and ids only — never names, license numbers,
 * or free-text reasons.
 */
final class HrController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly HrAssetsService $hr,
    ) {}

    /**
     * GET positions — the department position catalog within scope.
     */
    public function positions(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $positions = Position::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('name')
            ->get()
            ->map(fn (Position $p): array => [
                'id' => $p->getKey(),
                'departmentId' => $p->department_id,
                'code' => $p->code,
                'name' => $p->name,
                'status' => $p->status,
            ])
            ->values();

        return Envelope::success(data: $positions, request: $request);
    }

    /**
     * POST positions — create a position in the department catalog.
     */
    public function storePosition(StorePositionRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $department = DB::table('departments')
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('id', $request->validated('departmentId'))
            ->first();

        if ($department === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Department not found.', 404);
        }

        $position = Position::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => $department->facility_id,
            'department_id' => $department->id,
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'status' => $request->validated('status', Position::STATUS_ACTIVE),
            'created_by' => $this->currentStaffId($context, (string) $department->facility_id),
        ]);

        $this->audit->record('position.created', 'position', $position->getKey(), [
            'departmentId' => $position->department_id,
            'code' => $position->code,
        ], $request);

        return Envelope::success(data: [
            'id' => $position->getKey(),
            'departmentId' => $position->department_id,
            'code' => $position->code,
            'name' => $position->name,
            'status' => $position->status,
        ], status: 201, request: $request);
    }

    /**
     * GET shift-templates — the shift definitions within scope.
     */
    public function shiftTemplates(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $templates = ShiftTemplate::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ShiftTemplate $t): array => self::presentShiftTemplate($t))
            ->values();

        return Envelope::success(data: $templates, request: $request);
    }

    /**
     * POST shift-templates — create a shift definition.
     */
    public function storeShiftTemplate(StoreShiftTemplateRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $facilityId = $this->resolveFacilityId($context);

        $template = ShiftTemplate::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => $facilityId,
            'department_id' => $request->validated('departmentId'),
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'shift_type' => $request->validated('shiftType'),
            'starts_at' => $request->validated('startsAt'),
            'ends_at' => $request->validated('endsAt'),
            'working_minutes' => $request->validated('workingMinutes'),
            'status' => $request->validated('status', ShiftTemplate::STATUS_ACTIVE),
            'created_by' => $this->currentStaffId($context, $facilityId),
        ]);

        $this->audit->record('shift_template.created', 'shift_template', $template->getKey(), [
            'code' => $template->code,
            'shiftType' => $template->shift_type,
            'workingMinutes' => $template->working_minutes,
        ], $request);

        return Envelope::success(data: self::presentShiftTemplate($template), status: 201, request: $request);
    }

    /**
     * GET rosters — roster rows within scope, optionally filtered by date.
     */
    public function rosters(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $rosters = Roster::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($request->query('date') !== null, fn ($q) => $q->where('roster_date', $request->query('date')))
            ->when($request->query('staffId') !== null, fn ($q) => $q->where('staff_id', $request->query('staffId')))
            ->with('shiftTemplate')
            ->orderBy('roster_date')
            ->get()
            ->map(fn (Roster $r): array => self::presentRoster($r))
            ->values();

        return Envelope::success(data: $rosters, request: $request);
    }

    /**
     * POST rosters — assign a staff member to a shift on a date (conflict
     * detection: overlapping shifts and rest rules enforced in the service).
     */
    public function storeRoster(StoreRosterRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        // The staff member must be in scope.
        $staff = Staff::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('id', $request->validated('staffId'))
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Staff record not found.', 404);
        }

        $roster = $this->hr->createRoster(
            (string) $context->tenantId(),
            (string) $staff->facility_id,
            (string) $staff->getKey(),
            (string) $request->validated('shiftTemplateId'),
            (string) $request->validated('rosterDate'),
            $request->validated('notes'),
            $this->currentStaffId($context, (string) $staff->facility_id),
        );

        $this->audit->record('roster.created', 'roster', $roster->getKey(), [
            'staffId' => $roster->staff_id,
            'shiftTemplateId' => $roster->shift_template_id,
            'rosterDate' => $roster->roster_date->toDateString(),
        ], $request);

        return Envelope::success(data: self::presentRoster($roster->load('shiftTemplate')), status: 201, request: $request);
    }

    /**
     * POST rosters/{roster}/confirm — scheduled → confirmed (CAS).
     */
    public function confirmRoster(Request $request, Roster $roster): JsonResponse
    {
        AccessCheck::scoped($roster, write: true);

        $roster = $this->hr->confirmRoster($roster, $this->currentStaffId(TenantContext::current(), (string) $roster->facility_id));

        $this->audit->record('roster.confirmed', 'roster', $roster->getKey(), [
            'staffId' => $roster->staff_id,
            'rosterDate' => $roster->roster_date->toDateString(),
        ], $request);

        return Envelope::success(data: self::presentRoster($roster->load('shiftTemplate')), request: $request);
    }

    /**
     * GET attendance — attendance records within scope, filtered by date.
     */
    public function attendance(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $records = AttendanceRecord::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($request->query('date') !== null, fn ($q) => $q->where('attendance_date', $request->query('date')))
            ->when($request->query('staffId') !== null, fn ($q) => $q->where('staff_id', $request->query('staffId')))
            ->orderByDesc('attendance_date')
            ->get()
            ->map(fn (AttendanceRecord $r): array => self::presentAttendance($r))
            ->values();

        return Envelope::success(data: $records, request: $request);
    }

    /**
     * POST attendance — record a staff member's attendance for a day.
     */
    public function storeAttendance(StoreAttendanceRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $staff = Staff::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('id', $request->validated('staffId'))
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Staff record not found.', 404);
        }

        $record = AttendanceRecord::query()->firstOrNew([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $staff->facility_id,
            'staff_id' => (string) $staff->getKey(),
            'attendance_date' => $request->validated('attendanceDate'),
        ]);

        $isNew = ! $record->exists;

        $record->forceFill([
            'clock_in_at' => $request->validated('clockInAt', $record->clock_in_at),
            'clock_out_at' => $request->validated('clockOutAt', $record->clock_out_at),
            'status' => $request->validated('status', $record->status ?? AttendanceRecord::STATUS_PRESENT),
            'source' => $request->validated('source', $record->source ?? AttendanceRecord::SOURCE_CLOCK),
            'correction_status' => $record->correction_status ?? AttendanceRecord::CORRECTION_NONE,
            'lock_version' => $record->lock_version ?? 0,
            'created_by' => $record->created_by ?? $this->currentStaffId($context, (string) $staff->facility_id),
            'updated_by' => $this->currentStaffId($context, (string) $staff->facility_id),
        ])->save();

        $this->audit->record($isNew ? 'attendance.recorded' : 'attendance.updated', 'attendance_record', $record->getKey(), [
            'staffId' => $record->staff_id,
            'attendanceDate' => $record->attendance_date->toDateString(),
            'status' => $record->status,
        ], $request);

        return Envelope::success(
            data: self::presentAttendance($record),
            status: $isNew ? 201 : 200,
            request: $request,
        );
    }

    /**
     * POST attendance/{record}/correction — request a correction on an
     * attendance record (reason required). The record is untouched until an
     * HR approval applies it.
     */
    public function requestCorrection(RequestCorrectionRequest $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        AccessCheck::scoped($attendanceRecord, write: true);

        $record = $this->hr->requestAttendanceCorrection(
            $attendanceRecord,
            (string) $request->validated('reason'),
            $this->currentStaffId(TenantContext::current(), (string) $attendanceRecord->facility_id),
            $request->validated('clockInAt'),
            $request->validated('clockOutAt'),
        );

        $this->audit->record('attendance.correction_requested', 'attendance_record', $record->getKey(), [
            'staffId' => $record->staff_id,
            'attendanceDate' => $record->attendance_date->toDateString(),
        ], $request);

        return Envelope::success(data: self::presentAttendance($record), request: $request);
    }

    /**
     * POST attendance/{record}/correction/approve — approve a pending
     * correction (the only path that mutates the record's clock times).
     */
    public function approveCorrection(Request $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        AccessCheck::scoped($attendanceRecord, write: true);

        $record = $this->hr->approveAttendanceCorrection(
            $attendanceRecord,
            $this->currentStaffId(TenantContext::current(), (string) $attendanceRecord->facility_id),
        );

        $this->audit->record('attendance.correction_approved', 'attendance_record', $record->getKey(), [
            'staffId' => $record->staff_id,
            'attendanceDate' => $record->attendance_date->toDateString(),
        ], $request);

        return Envelope::success(data: self::presentAttendance($record), request: $request);
    }

    /**
     * POST attendance/{record}/correction/reject — reject a pending
     * correction (clock times are NOT touched).
     */
    public function rejectCorrection(Request $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        AccessCheck::scoped($attendanceRecord, write: true);

        $record = $this->hr->rejectAttendanceCorrection(
            $attendanceRecord,
            $this->currentStaffId(TenantContext::current(), (string) $attendanceRecord->facility_id),
        );

        $this->audit->record('attendance.correction_rejected', 'attendance_record', $record->getKey(), [
            'staffId' => $record->staff_id,
            'attendanceDate' => $record->attendance_date->toDateString(),
        ], $request);

        return Envelope::success(data: self::presentAttendance($record), request: $request);
    }

    /**
     * GET leave-types — the leave types within scope.
     */
    public function leaveTypes(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $types = LeaveType::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $t): array => [
                'id' => $t->getKey(),
                'code' => $t->code,
                'name' => $t->name,
                'paidDaysPerYear' => $t->paid_days_per_year,
                'carryoverDays' => $t->carryover_days,
                'status' => $t->status,
            ])
            ->values();

        return Envelope::success(data: $types, request: $request);
    }

    /**
     * POST leave-types — create a leave type with its entitlement.
     */
    public function storeLeaveType(StoreLeaveTypeRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $facilityId = $this->resolveFacilityId($context);

        $type = LeaveType::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => $facilityId,
            'code' => $request->validated('code'),
            'name' => $request->validated('name'),
            'paid_days_per_year' => $request->validated('paidDaysPerYear'),
            'carryover_days' => $request->validated('carryoverDays'),
            'status' => $request->validated('status', LeaveType::STATUS_ACTIVE),
            'created_by' => $this->currentStaffId($context, $facilityId),
        ]);

        $this->audit->record('leave_type.created', 'leave_type', $type->getKey(), [
            'code' => $type->code,
            'paidDaysPerYear' => $type->paid_days_per_year,
            'carryoverDays' => $type->carryover_days,
        ], $request);

        return Envelope::success(data: [
            'id' => $type->getKey(),
            'code' => $type->code,
            'name' => $type->name,
            'paidDaysPerYear' => $type->paid_days_per_year,
            'carryoverDays' => $type->carryover_days,
            'status' => $type->status,
        ], status: 201, request: $request);
    }

    /**
     * GET leave-requests — leave requests within scope.
     */
    public function leaveRequests(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $requests = LeaveRequest::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->when($request->query('staffId') !== null, fn ($q) => $q->where('staff_id', $request->query('staffId')))
            ->when($request->query('status') !== null, fn ($q) => $q->where('status', $request->query('status')))
            ->with('leaveType')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (LeaveRequest $l): array => self::presentLeaveRequest($l))
            ->values();

        return Envelope::success(data: $requests, request: $request);
    }

    /**
     * POST leave-requests — a staff member requests leave.
     */
    public function storeLeaveRequest(StoreLeaveRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $staff = Staff::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->where('id', $request->validated('staffId'))
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Staff record not found.', 404);
        }

        $type = LeaveType::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $staff->facility_id)
            ->where('id', $request->validated('leaveTypeId'))
            ->first();

        if ($type === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Leave type not found.', 404);
        }

        $leave = LeaveRequest::query()->create([
            'tenant_id' => (string) $context->tenantId(),
            'facility_id' => (string) $staff->facility_id,
            'staff_id' => (string) $staff->getKey(),
            'leave_type_id' => (string) $type->getKey(),
            'starts_on' => $request->validated('startsOn'),
            'ends_on' => $request->validated('endsOn'),
            'days_requested' => $request->validated('daysRequested'),
            'reason' => $request->validated('reason'),
            'status' => LeaveRequest::STATUS_PENDING,
            'lock_version' => 0,
            'created_by' => $this->currentStaffId($context, (string) $staff->facility_id),
        ]);

        $this->audit->record('leave.requested', 'leave_request', $leave->getKey(), [
            'staffId' => $leave->staff_id,
            'leaveTypeId' => $leave->leave_type_id,
            'daysRequested' => $leave->days_requested,
        ], $request);

        return Envelope::success(data: self::presentLeaveRequest($leave->load('leaveType')), status: 201, request: $request);
    }

    /**
     * POST leave-requests/{request}/approve — approve a pending request
     * (balance-checked and CAS-guarded — a double approval affects zero rows).
     */
    public function approveLeaveRequest(DecideLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        AccessCheck::scoped($leaveRequest, write: true);

        $leave = $this->hr->approveLeaveRequest(
            $leaveRequest,
            $this->currentStaffId(TenantContext::current(), (string) $leaveRequest->facility_id),
            $request->validated('notes'),
        );

        $this->audit->record('leave.approved', 'leave_request', $leave->getKey(), [
            'staffId' => $leave->staff_id,
            'leaveTypeId' => $leave->leave_type_id,
            'daysRequested' => $leave->days_requested,
        ], $request);

        return Envelope::success(data: self::presentLeaveRequest($leave->load('leaveType')), request: $request);
    }

    /**
     * POST leave-requests/{request}/reject — reject a pending request
     * (consumes nothing from the balance).
     */
    public function rejectLeaveRequest(DecideLeaveRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        AccessCheck::scoped($leaveRequest, write: true);

        $leave = $this->hr->rejectLeaveRequest(
            $leaveRequest,
            $this->currentStaffId(TenantContext::current(), (string) $leaveRequest->facility_id),
            $request->validated('notes'),
        );

        $this->audit->record('leave.rejected', 'leave_request', $leave->getKey(), [
            'staffId' => $leave->staff_id,
            'leaveTypeId' => $leave->leave_type_id,
        ], $request);

        return Envelope::success(data: self::presentLeaveRequest($leave->load('leaveType')), request: $request);
    }

    /**
     * GET payroll-exports — the audited export log (who exported what).
     */
    public function payrollExports(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $exports = PayrollExport::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('exported_at')
            ->get()
            ->map(fn (PayrollExport $e): array => [
                'id' => $e->getKey(),
                'periodStart' => $e->period_start->toDateString(),
                'periodEnd' => $e->period_end->toDateString(),
                'exportedByStaffId' => $e->exported_by_staff_id,
                'rowCount' => $e->row_count,
                'format' => $e->format,
                'payloadHash' => $e->payload_hash,
                'exportedAt' => $e->exported_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $exports, request: $request);
    }

    /**
     * POST payroll-exports — generate an audited payroll-ready export for a
     * period. The structured payload is delivered once at generation time;
     * the row records who exported what for which period (hashed).
     */
    public function generatePayrollExport(GeneratePayrollExportRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $facilityId = $this->resolveFacilityId($context);

        [$export, $payload] = $this->hr->generatePayrollExport(
            (string) $context->tenantId(),
            $facilityId,
            (string) $request->validated('periodStart'),
            (string) $request->validated('periodEnd'),
            $this->currentStaffId($context, $facilityId),
            (string) $request->validated('format', PayrollExport::FORMAT_PAYROLL_READY),
        );

        $this->audit->record('payroll_export.generated', 'payroll_export', $export->getKey(), [
            'periodStart' => $export->period_start->toDateString(),
            'periodEnd' => $export->period_end->toDateString(),
            'rowCount' => $export->row_count,
            'format' => $export->format,
        ], $request);

        return Envelope::success(data: [
            'export' => [
                'id' => $export->getKey(),
                'periodStart' => $export->period_start->toDateString(),
                'periodEnd' => $export->period_end->toDateString(),
                'exportedByStaffId' => $export->exported_by_staff_id,
                'rowCount' => $export->row_count,
                'format' => $export->format,
                'payloadHash' => $export->payload_hash,
                'exportedAt' => $export->exported_at?->toIso8601String(),
            ],
            'payload' => $payload,
        ], status: 201, request: $request);
    }

    private function resolveFacilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();

        if ($facilityId === null) {
            throw new ApiException(ErrorCodes::FACILITY_DENIED, 'A facility context is required for this operation.', 403);
        }

        return $facilityId;
    }

    private function currentStaffId(TenantContext $context, string $facilityId): ?string
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', $facilityId)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        return $staff?->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentShiftTemplate(ShiftTemplate $template): array
    {
        return [
            'id' => $template->getKey(),
            'departmentId' => $template->department_id,
            'code' => $template->code,
            'name' => $template->name,
            'shiftType' => $template->shift_type,
            'startsAt' => $template->starts_at?->format('H:i'),
            'endsAt' => $template->ends_at?->format('H:i'),
            'workingMinutes' => $template->working_minutes,
            'status' => $template->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentRoster(Roster $roster): array
    {
        return [
            'id' => $roster->getKey(),
            'staffId' => $roster->staff_id,
            'shiftTemplateId' => $roster->shift_template_id,
            'shiftType' => $roster->shiftTemplate?->shift_type,
            'rosterDate' => $roster->roster_date->toDateString(),
            'status' => $roster->status,
            'notes' => $roster->notes,
            'lockVersion' => $roster->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentAttendance(AttendanceRecord $record): array
    {
        return [
            'id' => $record->getKey(),
            'staffId' => $record->staff_id,
            'attendanceDate' => $record->attendance_date->toDateString(),
            'clockInAt' => $record->clock_in_at?->toIso8601String(),
            'clockOutAt' => $record->clock_out_at?->toIso8601String(),
            'status' => $record->status,
            'source' => $record->source,
            'correctionStatus' => $record->correction_status,
            'lockVersion' => $record->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentLeaveRequest(LeaveRequest $leave): array
    {
        return [
            'id' => $leave->getKey(),
            'staffId' => $leave->staff_id,
            'leaveTypeId' => $leave->leave_type_id,
            'leaveTypeCode' => $leave->leaveType?->code,
            'startsOn' => $leave->starts_on->toDateString(),
            'endsOn' => $leave->ends_on->toDateString(),
            'daysRequested' => $leave->days_requested,
            'status' => $leave->status,
            'decidedAt' => $leave->decided_at?->toIso8601String(),
            'lockVersion' => $leave->lock_version,
        ];
    }
}
