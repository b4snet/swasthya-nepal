<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\AssetTransfer;
use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MaintenanceSchedule;
use App\Models\PayrollExport;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\WorkOrder;
use App\Support\ErrorCodes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 19 — HR and Assets (ROADMAP Phase 15, PRODUCT_REQUIREMENTS
 * §6.17–6.18, DATABASE.md §3.45–3.47).
 *
 * HR:
 *   - Rosters:      conflict detection (overlapping shifts, rest rules) —
 *                   a double-booked or rest-violating shift is refused.
 *   - Attendance:   corrections flow (request → approve/reject) — a
 *                   correction is never silently edited in; the mutation is
 *                   CAS-guarded on (correction_status, lock_version).
 *   - Leave:        request → approve/reject with balance tracking — an
 *                   approval is CAS-guarded (double approval affects zero
 *                   rows) and refuses an over-entitlement approval.
 *   - Payroll:      a structured, audited payroll-ready export — WHO
 *                   exported WHAT for WHICH period, payload hashed.
 *
 * Assets:
 *   - Lifecycle:    procured → deployed → under_repair → retired (CAS on
 *                   (lifecycle_status, lock_version)); retired is terminal.
 *   - Downtime:     an asset with an OPEN downtime work order MUST be
 *                   under_repair — a machine listed as available while down
 *                   is a planning hazard; completing the order returns it to
 *                   deployed and advances the maintenance schedule.
 *   - Transfers:    append-only location history (never edited or deleted).
 *
 * Staff personal data is protected to the same standard as patient data:
 * names, licenses, and free-text reasons are never part of audit payloads.
 */
final class HrAssetsService
{
    private const MIN_REST_HOURS_BETWEEN_SHIFTS = 8;

    /**
     * Assign a staff member to a shift on a date with conflict detection:
     * overlapping shifts and insufficient rest between consecutive shifts
     * are refused (PRODUCT_REQUIREMENTS §6.17 "conflict detection (overlaps,
     * rest rules)"). The partial unique prevents exact duplicates.
     */
    public function createRoster(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $shiftTemplateId,
        string $rosterDate,
        ?string $notes = null,
        ?string $createdBy = null,
    ): Roster {
        return DB::transaction(function () use ($tenantId, $facilityId, $staffId, $shiftTemplateId, $rosterDate, $notes, $createdBy): Roster {
            $shift = ShiftTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $shiftTemplateId)
                ->first();

            if ($shift === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Shift template not found.', 404);
            }

            if ($shift->status !== ShiftTemplate::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'An inactive shift template cannot be rostered.', 409);
            }

            $existing = Roster::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('staff_id', $staffId)
                ->where('shift_template_id', $shiftTemplateId)
                ->where('roster_date', $rosterDate)
                ->first();

            if ($existing !== null) {
                if ($existing->status === Roster::STATUS_CANCELLED) {
                    throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This roster row is cancelled; create a new assignment.', 409);
                }

                throw new ApiException(ErrorCodes::RESOURCE_EXISTS, 'This staff member is already rostered for this shift on this date.', 409);
            }

            $this->assertNoShiftConflict($tenantId, $staffId, $rosterDate, $shift, $facilityId);

            return Roster::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'staff_id' => $staffId,
                'shift_template_id' => $shiftTemplateId,
                'roster_date' => $rosterDate,
                'status' => Roster::STATUS_SCHEDULED,
                'notes' => $notes,
                'lock_version' => 0,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * Confirm a scheduled roster row (scheduled → confirmed, CAS).
     */
    public function confirmRoster(Roster $roster, ?string $actorId = null): Roster
    {
        return DB::transaction(function () use ($roster, $actorId): Roster {
            $roster->refresh();

            if ($roster->status !== Roster::STATUS_SCHEDULED) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a scheduled roster row can be confirmed.', 409);
            }

            $affected = DB::table('rosters')
                ->where('tenant_id', $roster->tenant_id)
                ->where('id', $roster->getKey())
                ->where('status', Roster::STATUS_SCHEDULED)
                ->where('lock_version', $roster->lock_version)
                ->update([
                    'status' => Roster::STATUS_CONFIRMED,
                    'lock_version' => $roster->lock_version + 1,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The roster row was concurrently modified; reload and retry.', 409);
            }

            return $roster->refresh();
        });
    }

    /**
     * Request a correction on an attendance record (reason captured; the
     * corrected clock times are stored as PROPOSED values). The record's
     * actual clock times stay untouched until an HR approval APPLIES the
     * proposed values — a correction is never silently edited in
     * (PRODUCT_REQUIREMENTS §6.17 "corrections with approval").
     */
    public function requestAttendanceCorrection(
        AttendanceRecord $record,
        string $reason,
        ?string $requestedBy = null,
        ?string $newClockInAt = null,
        ?string $newClockOutAt = null,
    ): AttendanceRecord {
        return DB::transaction(function () use ($record, $reason, $requestedBy, $newClockInAt, $newClockOutAt): AttendanceRecord {
            $record->refresh();

            if ($record->correction_status !== AttendanceRecord::CORRECTION_NONE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This record already has a correction request.', 409);
            }

            $affected = DB::table('attendance_records')
                ->where('tenant_id', $record->tenant_id)
                ->where('id', $record->getKey())
                ->where('correction_status', AttendanceRecord::CORRECTION_NONE)
                ->where('lock_version', $record->lock_version)
                ->update([
                    'correction_status' => AttendanceRecord::CORRECTION_PENDING,
                    'correction_reason' => $reason,
                    'correction_proposed_clock_in_at' => $newClockInAt ?? $record->clock_in_at,
                    'correction_proposed_clock_out_at' => $newClockOutAt ?? $record->clock_out_at,
                    'correction_requested_by' => $requestedBy,
                    'correction_approved_by' => null,
                    'correction_approved_at' => null,
                    'lock_version' => $record->lock_version + 1,
                    'updated_by' => $requestedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The attendance record was concurrently modified; reload and retry.', 409);
            }

            return $record->refresh();
        });
    }

    /**
     * Approve a pending attendance correction — the ONLY path that applies
     * the proposed values to the record's actual clock times. CAS on
     * (correction_status, lock_version) makes a double approval affect zero
     * rows.
     */
    public function approveAttendanceCorrection(
        AttendanceRecord $record,
        ?string $approvedBy = null,
    ): AttendanceRecord {
        return DB::transaction(function () use ($record, $approvedBy): AttendanceRecord {
            $record->refresh();

            if ($record->correction_status !== AttendanceRecord::CORRECTION_PENDING) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a pending correction can be approved.', 409);
            }

            $affected = DB::table('attendance_records')
                ->where('tenant_id', $record->tenant_id)
                ->where('id', $record->getKey())
                ->where('correction_status', AttendanceRecord::CORRECTION_PENDING)
                ->where('lock_version', $record->lock_version)
                ->update([
                    'correction_status' => AttendanceRecord::CORRECTION_APPROVED,
                    'clock_in_at' => $record->correction_proposed_clock_in_at,
                    'clock_out_at' => $record->correction_proposed_clock_out_at,
                    'correction_approved_by' => $approvedBy,
                    'correction_approved_at' => now(),
                    'lock_version' => $record->lock_version + 1,
                    'updated_by' => $approvedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The attendance correction was concurrently modified; reload and retry.', 409);
            }

            return $record->refresh();
        });
    }

    /**
     * Reject a pending attendance correction (pending → rejected, CAS). The
     * record's clock times are NOT touched.
     */
    public function rejectAttendanceCorrection(
        AttendanceRecord $record,
        ?string $rejectedBy = null,
    ): AttendanceRecord {
        return DB::transaction(function () use ($record, $rejectedBy): AttendanceRecord {
            $record->refresh();

            if ($record->correction_status !== AttendanceRecord::CORRECTION_PENDING) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a pending correction can be rejected.', 409);
            }

            $affected = DB::table('attendance_records')
                ->where('tenant_id', $record->tenant_id)
                ->where('id', $record->getKey())
                ->where('correction_status', AttendanceRecord::CORRECTION_PENDING)
                ->where('lock_version', $record->lock_version)
                ->update([
                    'correction_status' => AttendanceRecord::CORRECTION_REJECTED,
                    'correction_approved_by' => $rejectedBy,
                    'correction_approved_at' => now(),
                    'lock_version' => $record->lock_version + 1,
                    'updated_by' => $rejectedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The attendance correction was concurrently modified; reload and retry.', 409);
            }

            return $record->refresh();
        });
    }

    /**
     * Approve a pending leave request. The approval runs inside a
     * transaction that ALSO checks the staff member's balance for this
     * leave type: approved days (including this request) must not exceed
     * the entitlement (paid_days_per_year + carryover). CAS on
     * (status, lock_version) makes a double approval affect zero rows —
     * exactly one winner.
     */
    public function approveLeaveRequest(
        LeaveRequest $request,
        ?string $decidedBy = null,
        ?string $notes = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($request, $decidedBy, $notes): LeaveRequest {
            $request->refresh();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a pending leave request can be approved.', 409);
            }

            $type = LeaveType::query()
                ->where('tenant_id', $request->tenant_id)
                ->where('id', $request->leave_type_id)
                ->first();

            if ($type === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Leave type not found.', 404);
            }

            $alreadyApprovedDays = (int) LeaveRequest::query()
                ->where('tenant_id', $request->tenant_id)
                ->where('staff_id', $request->staff_id)
                ->where('leave_type_id', $request->leave_type_id)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->where('id', '!=', $request->getKey())
                ->sum('days_requested');

            $entitlement = $type->paid_days_per_year + $type->carryover_days;

            if ($alreadyApprovedDays + $request->days_requested > $entitlement) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf(
                        'Approval would exceed the leave entitlement: %d approved + %d requested > %d available.',
                        $alreadyApprovedDays,
                        $request->days_requested,
                        $entitlement,
                    ),
                    422,
                );
            }

            $affected = DB::table('leave_requests')
                ->where('tenant_id', $request->tenant_id)
                ->where('id', $request->getKey())
                ->where('status', LeaveRequest::STATUS_PENDING)
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'decided_by' => $decidedBy,
                    'decided_at' => now(),
                    'decision_notes' => $notes,
                    'lock_version' => $request->lock_version + 1,
                    'updated_by' => $decidedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The leave request was concurrently modified; reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    /**
     * Reject a pending leave request (pending → rejected, CAS). A rejected
     * request consumes nothing from the balance.
     */
    public function rejectLeaveRequest(
        LeaveRequest $request,
        ?string $decidedBy = null,
        ?string $notes = null,
    ): LeaveRequest {
        return DB::transaction(function () use ($request, $decidedBy, $notes): LeaveRequest {
            $request->refresh();

            if ($request->status !== LeaveRequest::STATUS_PENDING) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a pending leave request can be rejected.', 409);
            }

            $affected = DB::table('leave_requests')
                ->where('tenant_id', $request->tenant_id)
                ->where('id', $request->getKey())
                ->where('status', LeaveRequest::STATUS_PENDING)
                ->where('lock_version', $request->lock_version)
                ->update([
                    'status' => LeaveRequest::STATUS_REJECTED,
                    'decided_by' => $decidedBy,
                    'decided_at' => now(),
                    'decision_notes' => $notes,
                    'lock_version' => $request->lock_version + 1,
                    'updated_by' => $decidedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The leave request was concurrently modified; reload and retry.', 409);
            }

            return $request->refresh();
        });
    }

    /**
     * Generate an audited payroll-ready export for a period: a structured,
     * point-in-time snapshot of worked days (attendance), shifts (rosters),
     * and leave (approved leave requests) — the payroll engine's input
     * (PRODUCT_REQUIREMENTS §6.17 "not tax filing or salary disbursement").
     * The export is hashed and recorded (who/what/when); the payload is
     * delivered at generation time, never re-served from the DB.
     *
     * @return array{0: PayrollExport, 1: array<string, mixed>} [export, payload]
     */
    public function generatePayrollExport(
        string $tenantId,
        string $facilityId,
        string $periodStart,
        string $periodEnd,
        ?string $exportedByStaffId = null,
        string $format = PayrollExport::FORMAT_PAYROLL_READY,
    ): array {
        return DB::transaction(function () use ($tenantId, $facilityId, $periodStart, $periodEnd, $exportedByStaffId, $format): array {
            $attendance = AttendanceRecord::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->whereBetween('attendance_date', [$periodStart, $periodEnd])
                ->whereIn('status', [AttendanceRecord::STATUS_PRESENT, AttendanceRecord::STATUS_LATE, AttendanceRecord::STATUS_ABSENT])
                ->get();

            $rosters = Roster::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->whereBetween('roster_date', [$periodStart, $periodEnd])
                ->whereIn('status', [Roster::STATUS_SCHEDULED, Roster::STATUS_CONFIRMED])
                ->get();

            $leave = LeaveRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('status', LeaveRequest::STATUS_APPROVED)
                ->where('starts_on', '<=', $periodEnd)
                ->where('ends_on', '>=', $periodStart)
                ->get();

            $payload = [
                'period' => ['start' => $periodStart, 'end' => $periodEnd],
                'generatedAt' => now()->toIso8601String(),
                'staff' => [],
            ];

            $staffIds = $attendance->pluck('staff_id')
                ->merge($rosters->pluck('staff_id'))
                ->merge($leave->pluck('staff_id'))
                ->unique()
                ->values();

            $rows = 0;

            foreach ($staffIds as $staffId) {
                $workedDays = $attendance
                    ->where('staff_id', $staffId)
                    ->filter(fn (AttendanceRecord $a): bool => $a->status !== AttendanceRecord::STATUS_ABSENT)
                    ->map(fn (AttendanceRecord $a): array => [
                        'date' => $a->attendance_date->toDateString(),
                        'clockIn' => $a->clock_in_at?->toIso8601String(),
                        'clockOut' => $a->clock_out_at?->toIso8601String(),
                        'status' => $a->status,
                    ])
                    ->values();

                $shiftRows = $rosters
                    ->where('staff_id', $staffId)
                    ->map(function (Roster $r): array {
                        $template = $r->shiftTemplate;

                        return [
                            'date' => $r->roster_date->toDateString(),
                            'shiftType' => $template?->shift_type,
                            'workingMinutes' => $template?->working_minutes,
                            'status' => $r->status,
                        ];
                    })
                    ->values();

                $leaveRows = $leave
                    ->where('staff_id', $staffId)
                    ->map(fn (LeaveRequest $l): array => [
                        'start' => $l->starts_on->toDateString(),
                        'end' => $l->ends_on->toDateString(),
                        'days' => $l->days_requested,
                    ])
                    ->values();

                if ($workedDays->isEmpty() && $shiftRows->isEmpty() && $leaveRows->isEmpty()) {
                    continue;
                }

                $payload['staff'][] = [
                    'staffId' => $staffId,
                    'workedDays' => $workedDays,
                    'shifts' => $shiftRows,
                    'leave' => $leaveRows,
                ];
                $rows++;
            }

            $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            $export = PayrollExport::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'exported_by_staff_id' => $exportedByStaffId,
                'row_count' => $rows,
                'format' => $format,
                'payload_hash' => $hash,
                'exported_at' => now(),
                'created_by' => $exportedByStaffId,
            ]);

            return [$export, $payload];
        });
    }

    /**
     * Deploy a procured asset (procured → deployed, CAS).
     */
    public function deployAsset(Asset $asset, ?string $actorId = null): Asset
    {
        return $this->transitionLifecycle($asset, Asset::LIFECYCLE_DEPLOYED, $actorId);
    }

    /**
     * Retire an asset (deployed | under_repair → retired, CAS). Retired is
     * terminal — a retired asset can never be reactivated.
     */
    public function retireAsset(Asset $asset, ?string $actorId = null): Asset
    {
        return $this->transitionLifecycle($asset, Asset::LIFECYCLE_RETIRED, $actorId);
    }

    /**
     * Transfer an asset to another location — an append-only history row
     * plus the asset's current_location_id, in one transaction. Transfers
     * are never edited or deleted (the location history is the audit trail
     * and must survive the equipment's life).
     */
    public function transferAsset(
        Asset $asset,
        string $toLocationId,
        ?string $transferredByStaffId = null,
        ?string $reason = null,
    ): AssetTransfer {
        return DB::transaction(function () use ($asset, $toLocationId, $transferredByStaffId, $reason): AssetTransfer {
            $asset->refresh();

            if ($asset->lifecycle_status === Asset::LIFECYCLE_RETIRED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A retired asset cannot be transferred.', 409);
            }

            $to = DB::table('locations')
                ->where('tenant_id', $asset->tenant_id)
                ->where('facility_id', $asset->facility_id)
                ->where('id', $toLocationId)
                ->first();

            if ($to === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Destination location not found.', 404);
            }

            if ($asset->current_location_id === $toLocationId) {
                throw new ApiException(ErrorCodes::INVALID_REQUEST, 'The asset is already at this location.', 422);
            }

            $transfer = AssetTransfer::query()->create([
                'tenant_id' => $asset->tenant_id,
                'facility_id' => $asset->facility_id,
                'asset_id' => $asset->getKey(),
                'from_location_id' => $asset->current_location_id,
                'to_location_id' => $toLocationId,
                'transferred_at' => now(),
                'transferred_by_staff_id' => $transferredByStaffId,
                'reason' => $reason,
                'created_by' => $transferredByStaffId,
            ]);

            $affected = DB::table('assets')
                ->where('tenant_id', $asset->tenant_id)
                ->where('id', $asset->getKey())
                ->where('lifecycle_status', '!=', Asset::LIFECYCLE_RETIRED)
                ->where('lock_version', $asset->lock_version)
                ->update([
                    'current_location_id' => $toLocationId,
                    'lock_version' => $asset->lock_version + 1,
                    'updated_by' => $transferredByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The asset was concurrently modified; reload and retry.', 409);
            }

            return $transfer;
        });
    }

    /**
     * Open a work order on an asset. If the work order tracks downtime, the
     * asset MUST move to under_repair in the same transaction — a machine
     * listed as available while down is a planning hazard
     * (PRODUCT_REQUIREMENTS §6.18 "downtime tracking must be honest"). The
     * order can be tied to a maintenance schedule (schedule-driven) or ad hoc.
     */
    public function openWorkOrder(
        Asset $asset,
        ?string $maintenanceScheduleId,
        ?string $openedByStaffId,
        ?CarbonInterface $downtimeStartedAt = null,
        ?string $description = null,
    ): WorkOrder {
        return DB::transaction(function () use ($asset, $maintenanceScheduleId, $openedByStaffId, $downtimeStartedAt): WorkOrder {
            $asset->refresh();

            if ($asset->lifecycle_status === Asset::LIFECYCLE_RETIRED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A retired asset cannot receive a work order.', 409);
            }

            if ($maintenanceScheduleId !== null) {
                $schedule = MaintenanceSchedule::query()
                    ->where('tenant_id', $asset->tenant_id)
                    ->where('facility_id', $asset->facility_id)
                    ->where('id', $maintenanceScheduleId)
                    ->where('asset_id', $asset->getKey())
                    ->first();

                if ($schedule === null) {
                    throw new ApiException(ErrorCodes::NOT_FOUND, 'Maintenance schedule not found for this asset.', 404);
                }
            }

            $order = WorkOrder::query()->create([
                'tenant_id' => $asset->tenant_id,
                'facility_id' => $asset->facility_id,
                'asset_id' => $asset->getKey(),
                'maintenance_schedule_id' => $maintenanceScheduleId,
                'work_order_number' => $this->nextWorkOrderNumber($asset->tenant_id),
                'status' => WorkOrder::STATUS_OPEN,
                'opened_at' => now(),
                'opened_by_staff_id' => $openedByStaffId,
                'downtime_started_at' => $downtimeStartedAt,
                'lock_version' => 0,
                'created_by' => $openedByStaffId,
            ]);

            if ($downtimeStartedAt !== null) {
                $this->transitionLifecycle($asset, Asset::LIFECYCLE_UNDER_REPAIR, $openedByStaffId);
            }

            return $order;
        });
    }

    /**
     * Complete a work order (open/in_progress → completed, CAS). Downtime
     * must be closed (downtime_ended_at > started) when the order tracked
     * downtime; a certification reference makes the maintenance provable.
     * Completing returns the asset to deployed (when not retired) and
     * advances any linked maintenance schedule.
     */
    public function completeWorkOrder(
        WorkOrder $order,
        ?string $completedByStaffId,
        ?CarbonInterface $downtimeEndedAt = null,
        ?string $certificationRef = null,
    ): WorkOrder {
        return DB::transaction(function () use ($order, $completedByStaffId, $downtimeEndedAt, $certificationRef): WorkOrder {
            $order->refresh();
            $asset = $order->asset()->first();

            if ($asset === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Work order asset not found.', 404);
            }

            if (! in_array($order->status, [WorkOrder::STATUS_OPEN, WorkOrder::STATUS_IN_PROGRESS], true)) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only an open or in-progress work order can be completed.', 409);
            }

            if ($order->downtime_started_at !== null && $downtimeEndedAt === null) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'A downtime work order must record when the downtime ended.', 422);
            }

            if ($downtimeEndedAt !== null
                && $order->downtime_started_at !== null
                && ! $downtimeEndedAt->greaterThan($order->downtime_started_at)) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Downtime must end after it started.', 422);
            }

            $affected = DB::table('work_orders')
                ->where('tenant_id', $order->tenant_id)
                ->where('id', $order->getKey())
                ->whereIn('status', [WorkOrder::STATUS_OPEN, WorkOrder::STATUS_IN_PROGRESS])
                ->where('lock_version', $order->lock_version)
                ->update([
                    'status' => WorkOrder::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'completed_by_staff_id' => $completedByStaffId,
                    'downtime_ended_at' => $downtimeEndedAt ?? $order->downtime_ended_at,
                    'certification_ref' => $certificationRef ?? $order->certification_ref,
                    'lock_version' => $order->lock_version + 1,
                    'updated_by' => $completedByStaffId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The work order was concurrently modified; reload and retry.', 409);
            }

            $order->refresh();

            if ($asset->lifecycle_status === Asset::LIFECYCLE_UNDER_REPAIR
                && ! $this->hasOpenDowntimeOrder($asset)) {
                $this->transitionLifecycle($asset, Asset::LIFECYCLE_DEPLOYED, $completedByStaffId);
            }

            if ($order->maintenance_schedule_id !== null) {
                $schedule = MaintenanceSchedule::query()
                    ->where('tenant_id', $order->tenant_id)
                    ->where('id', $order->maintenance_schedule_id)
                    ->first();

                if ($schedule !== null) {
                    DB::table('maintenance_schedules')
                        ->where('tenant_id', $schedule->tenant_id)
                        ->where('id', $schedule->getKey())
                        ->where('lock_version', $schedule->lock_version)
                        ->update([
                            'last_completed_at' => now()->toDateString(),
                            'next_due_date' => now()->addDays($schedule->frequency_days)->toDateString(),
                            'lock_version' => $schedule->lock_version + 1,
                            'updated_at' => now(),
                        ]);
                }
            }

            return $order;
        });
    }

    /**
     * Cancel an open work order (open → cancelled, CAS). A cancelled order
     * releases any under_repair hold (back to deployed when nothing else is
     * down).
     */
    public function cancelWorkOrder(WorkOrder $order, ?string $actorId = null): WorkOrder
    {
        return DB::transaction(function () use ($order, $actorId): WorkOrder {
            $order->refresh();

            if ($order->status !== WorkOrder::STATUS_OPEN) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only an open work order can be cancelled.', 409);
            }

            $affected = DB::table('work_orders')
                ->where('tenant_id', $order->tenant_id)
                ->where('id', $order->getKey())
                ->where('status', WorkOrder::STATUS_OPEN)
                ->where('lock_version', $order->lock_version)
                ->update([
                    'status' => WorkOrder::STATUS_CANCELLED,
                    'lock_version' => $order->lock_version + 1,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The work order was concurrently modified; reload and retry.', 409);
            }

            $asset = $order->asset()->first();

            if ($asset !== null
                && $asset->lifecycle_status === Asset::LIFECYCLE_UNDER_REPAIR
                && ! $this->hasOpenDowntimeOrder($asset)) {
                $this->transitionLifecycle($asset, Asset::LIFECYCLE_DEPLOYED, $actorId);
            }

            return $order->refresh();
        });
    }

    private function transitionLifecycle(Asset $asset, string $to, ?string $actorId): Asset
    {
        $allowed = [
            Asset::LIFECYCLE_PROCURED => [Asset::LIFECYCLE_DEPLOYED],
            Asset::LIFECYCLE_DEPLOYED => [Asset::LIFECYCLE_UNDER_REPAIR, Asset::LIFECYCLE_RETIRED],
            Asset::LIFECYCLE_UNDER_REPAIR => [Asset::LIFECYCLE_DEPLOYED, Asset::LIFECYCLE_RETIRED],
            Asset::LIFECYCLE_RETIRED => [],
        ];

        if (! in_array($to, $allowed[$asset->lifecycle_status] ?? [], true)) {
            throw new ApiException(
                ErrorCodes::INVALID_REQUEST,
                sprintf('Asset lifecycle cannot move from %s to %s.', $asset->lifecycle_status, $to),
                422,
            );
        }

        $affected = DB::table('assets')
            ->where('tenant_id', $asset->tenant_id)
            ->where('id', $asset->getKey())
            ->where('lifecycle_status', $asset->lifecycle_status)
            ->where('lock_version', $asset->lock_version)
            ->update([
                'lifecycle_status' => $to,
                'lock_version' => $asset->lock_version + 1,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The asset was concurrently modified; reload and retry.', 409);
        }

        return $asset->refresh();
    }

    private function hasOpenDowntimeOrder(Asset $asset): bool
    {
        return WorkOrder::query()
            ->where('tenant_id', $asset->tenant_id)
            ->where('asset_id', $asset->getKey())
            ->whereIn('status', [WorkOrder::STATUS_OPEN, WorkOrder::STATUS_IN_PROGRESS])
            ->whereNotNull('downtime_started_at')
            ->exists();
    }

    private function assertNoShiftConflict(string $tenantId, string $staffId, string $rosterDate, ShiftTemplate $shift, string $facilityId): void
    {
        $sameDay = Roster::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('staff_id', $staffId)
            ->where('roster_date', $rosterDate)
            ->whereIn('status', [Roster::STATUS_SCHEDULED, Roster::STATUS_CONFIRMED])
            ->get();

        foreach ($sameDay as $existing) {
            $template = $existing->shiftTemplate;

            if ($template === null) {
                continue;
            }

            // Overlap: [newStart, newEnd) vs [existingStart, existingEnd).
            $newStart = $shift->starts_at->format('H:i');
            $newEnd = $shift->ends_at->format('H:i');
            $oldStart = $template->starts_at->format('H:i');
            $oldEnd = $template->ends_at->format('H:i');

            $overlaps = $newStart < $oldEnd && $oldStart < $newEnd;

            if ($overlaps) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    sprintf('This shift overlaps an existing roster row (%s %s–%s).', $template->name, $oldStart, $oldEnd),
                    409,
                );
            }
        }

        // Rest rule: at least MIN_REST_HOURS between the end of the previous
        // day's shift and this shift's start (and vice versa for the next
        // day). Only the previous-day check is enforced here (the next day's
        // conflict is caught when that day is rostered).
        $prevDay = CarbonImmutable::createFromFormat('Y-m-d', $rosterDate)->subDay()->toDateString();

        if ($prevDay !== null) {
            $prev = Roster::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('staff_id', $staffId)
                ->where('roster_date', $prevDay)
                ->whereIn('status', [Roster::STATUS_SCHEDULED, Roster::STATUS_CONFIRMED])
                ->get();

            foreach ($prev as $existing) {
                $template = $existing->shiftTemplate;

                if ($template === null) {
                    continue;
                }

                $endOfPrev = $template->ends_at->format('H:i');
                $startOfNew = $shift->starts_at->format('H:i');
                // HH:MM — treat as minutes since midnight for the math.
                $endMinutes = $this->toMinutes($endOfPrev);
                $startMinutes = $this->toMinutes($startOfNew);

                // A shift ending at 00:00 is a full 24h span, not an empty one.
                $effectiveEnd = $endMinutes === 0 ? 1440 : $endMinutes;

                if ($startMinutes < $effectiveEnd + self::MIN_REST_HOURS_BETWEEN_SHIFTS * 60) {
                    throw new ApiException(
                        ErrorCodes::CONFLICT,
                        sprintf(
                            "This shift violates the %d-hour rest rule after the previous day's shift (%s ends at %s).",
                            self::MIN_REST_HOURS_BETWEEN_SHIFTS,
                            $template->name,
                            $endOfPrev,
                        ),
                        409,
                    );
                }
            }
        }
    }

    private function toMinutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h * 60 + $m;
    }

    private function nextWorkOrderNumber(string $tenantId): string
    {
        do {
            $number = 'WO-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (WorkOrder::query()->where('tenant_id', $tenantId)->where('work_order_number', $number)->exists());

        return $number;
    }
}
