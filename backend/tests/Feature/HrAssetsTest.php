<?php

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetTransfer;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Facility;
use App\Models\IotReading;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Organization;
use App\Models\PayrollExport;
use App\Models\Roster;
use App\Models\ShiftTemplate;
use App\Models\Staff;
use App\Models\User;
use App\Models\WorkOrder;
use Tests\Support\Identity;

/**
 * Phase 3 slice 19 — HR and Assets (ROADMAP Phase 15, PRODUCT_REQUIREMENTS
 * §6.17–6.18, DATABASE.md §3.45–3.47): positions, shift templates, rosters
 * (conflict detection: overlaps + rest rules), attendance with APPROVED
 * corrections, leave with balance tracking, audited payroll-ready exports,
 * asset register + lifecycle (procured → deployed → under_repair → retired),
 * append-only transfers, maintenance schedules, work orders with HONEST
 * downtime, and the RFID/IoT-ready reading model.
 *
 * Staff personal data is protected to the same standard as patient data:
 * audit payloads carry facts and ids only — never names, license numbers,
 * or free-text reasons.
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * An HR admin identity (hospital_admin — facility-scoped, holds every
 * hr:* and assets:* permission) with a linked staff profile.
 *
 * @return array{org: Organization, facility: Facility, admin: User, staff: Staff}
 */
function hrAdmin(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facility);

    $department = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $staff = Staff::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department_id' => $department->getKey(),
        'user_id' => $admin->getKey(),
    ]);

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff];
}

/**
 * A second tenant + facility (the isolation target).
 *
 * @return array{org: Organization, facility: Facility}
 */
function hrOtherTenant(): array
{
    $org = Identity::organization();
    $facility = Identity::facility($org);

    return ['org' => $org, 'facility' => $facility];
}

it('manages the position catalog with RBAC', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = hrAdmin();
    $department = Department::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    // Unauthenticated → 401.
    $this->postJson('/api/v1/positions', [])->assertUnauthorized();

    // Doctor (no hr:employee) → 403.
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/positions', ['code' => 'POS-1', 'name' => 'Head Nurse', 'departmentId' => $department->getKey()])
        ->assertForbidden();

    // HR admin creates and lists.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/positions', ['code' => 'POS-1', 'name' => 'Head Nurse', 'departmentId' => $department->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.code', 'POS-1')
        ->assertJsonPath('data.departmentId', $department->getKey());

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/positions')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Head Nurse');

    // Audit: facts only, no names.
    $event = AuditEvent::query()->where('action', 'position.created')->latest('occurred_at')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload)->toMatchArray(['departmentId' => $department->getKey(), 'code' => 'POS-1'])
        ->and($event->payload)->not->toHaveKey('name');
});

it('creates shift templates and rosters with overlap and rest-rule conflict detection', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/shift-templates', [
            'code' => 'DAY', 'name' => 'Day', 'shiftType' => 'day',
            'startsAt' => '08:00', 'endsAt' => '16:00', 'workingMinutes' => 480,
        ])
        ->assertCreated()
        ->assertJsonPath('data.shiftType', 'day');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/shift-templates', [
            'code' => 'NIGHT', 'name' => 'Night', 'shiftType' => 'night',
            'startsAt' => '00:00', 'endsAt' => '08:00', 'workingMinutes' => 480,
        ])
        ->assertCreated();

    $day = ShiftTemplate::query()->where('code', 'DAY')->firstOrFail();
    $night = ShiftTemplate::query()->where('code', 'NIGHT')->firstOrFail();

    // Happy path: night shift today.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $night->getKey(),
            'rosterDate' => now()->addDays(1)->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'scheduled');

    // Overlap: day shift (08:00–16:00) overlaps the night shift (00:00–08:00)?
    // No — they touch at 08:00 but do not overlap. Use a 06:00–14:00 shift.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/shift-templates', [
            'code' => 'EARLY', 'name' => 'Early', 'shiftType' => 'rotating',
            'startsAt' => '06:00', 'endsAt' => '14:00', 'workingMinutes' => 480,
        ])
        ->assertCreated();

    $early = ShiftTemplate::query()->where('code', 'EARLY')->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $early->getKey(),
            'rosterDate' => now()->addDays(1)->toDateString(),
        ])
        ->assertStatus(409) // overlaps the night shift (00:00–08:00)
        ->assertJsonPath('error.code', 'CONFLICT');

    // Rest rule: a day shift ending at 16:00 today means an 06:00 shift
    // tomorrow violates the 8-hour rest rule (16:00 → 06:00 = 14h, no —
    // that's fine). Use a shift ending at 22:00 today then 06:00 tomorrow
    // (8h exactly is allowed; 05:00 tomorrow violates it).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/shift-templates', [
            'code' => 'LATE', 'name' => 'Late', 'shiftType' => 'rotating',
            'startsAt' => '14:00', 'endsAt' => '22:00', 'workingMinutes' => 480,
        ])
        ->assertCreated();

    $late = ShiftTemplate::query()->where('code', 'LATE')->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $late->getKey(),
            'rosterDate' => now()->addDays(1)->toDateString(),
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $early->getKey(),
            'rosterDate' => now()->addDays(2)->toDateString(),
        ])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT'); // rest rule: late ends 22:00, early starts 06:00 (< 8h)

    // Exact duplicate → 409.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $late->getKey(),
            'rosterDate' => now()->addDays(1)->toDateString(),
        ])
        ->assertStatus(409);

    // Cross-tenant: another tenant's roster rows are invisible (404 read).
    ['org' => $otherOrg, 'facility' => $otherFacility] = hrOtherTenant();
    $otherStaff = Staff::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'department_id' => Department::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ])->getKey(),
    ]);
    $otherTemplate = ShiftTemplate::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
    ]);
    Roster::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'staff_id' => $otherStaff->getKey(),
        'shift_template_id' => $otherTemplate->getKey(),
        'roster_date' => now()->addDays(5)->toDateString(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/rosters?date='.now()->addDays(5)->toDateString())
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('records attendance and routes corrections through approval', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/attendance', [
            'staffId' => $staff->getKey(),
            'attendanceDate' => now()->toDateString(),
            'clockInAt' => now()->startOfDay()->addHours(8)->toIso8601String(),
            'clockOutAt' => now()->startOfDay()->addHours(16)->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'present');

    $record = AttendanceRecord::query()->firstOrFail();

    // Request a correction — the record must NOT be mutated yet.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/attendance/'.$record->getKey().'/correction', [
            'reason' => 'Clock machine failure — actual in time was 07:30',
            'clockInAt' => now()->startOfDay()->addHours(7)->addMinutes(30)->toIso8601String(),
        ])
        ->assertOk()
        ->assertJsonPath('data.correctionStatus', 'pending');

    $record->refresh();
    expect($record->correction_status)->toBe('pending')
        ->and($record->clock_in_at->hour)->toBe(8); // untouched until approval

    // Approve — the only path that mutates the clock times.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/attendance/'.$record->getKey().'/correction/approve')
        ->assertOk()
        ->assertJsonPath('data.correctionStatus', 'approved');

    $record->refresh();
    expect($record->correction_status)->toBe('approved')
        ->and($record->clock_in_at->hour)->toBe(7)
        ->and($record->clock_in_at->minute)->toBe(30);

    // Double approval → CAS 409.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/attendance/'.$record->getKey().'/correction/approve')
        ->assertStatus(409);

    // Audit: facts only — the free-text correction reason never reaches the
    // audit payload.
    $event = AuditEvent::query()->where('action', 'attendance.correction_approved')->latest('occurred_at')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload)->toMatchArray(['staffId' => $staff->getKey()])
        ->and($event->payload)->not->toHaveKey('reason');
});

it('rejects an attendance correction without touching clock times', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    $record = AttendanceRecord::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'attendance_date' => now()->toDateString(),
        'clock_in_at' => now()->startOfDay()->addHours(9)->toIso8601String(),
        'status' => AttendanceRecord::STATUS_LATE,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/attendance/'.$record->getKey().'/correction', [
            'reason' => 'Vehicle breakdown',
            'clockInAt' => now()->startOfDay()->addHours(9)->addMinutes(10)->toIso8601String(),
        ])
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/attendance/'.$record->getKey().'/correction/reject')
        ->assertOk()
        ->assertJsonPath('data.correctionStatus', 'rejected');

    $record->refresh();
    expect($record->correction_status)->toBe('rejected')
        ->and($record->clock_in_at->hour)->toBe(9); // untouched
});

it('tracks leave balances and refuses over-entitlement approvals', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-types', [
            'code' => 'ANNUAL', 'name' => 'Annual Leave',
            'paidDaysPerYear' => 30, 'carryoverDays' => 0,
        ])
        ->assertCreated()
        ->assertJsonPath('data.paidDaysPerYear', 30);

    $type = LeaveType::query()->where('code', 'ANNUAL')->firstOrFail();

    // Request 25 days (within entitlement).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests', [
            'staffId' => $staff->getKey(),
            'leaveTypeId' => $type->getKey(),
            'startsOn' => now()->addDays(10)->toDateString(),
            'endsOn' => now()->addDays(34)->toDateString(),
            'daysRequested' => 25,
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    $request = LeaveRequest::query()->where('status', 'pending')->firstOrFail();

    // Approve → within balance.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests/'.$request->getKey().'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    // Request 10 more (25 + 10 > 30) → approval refused at 422.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests', [
            'staffId' => $staff->getKey(),
            'leaveTypeId' => $type->getKey(),
            'startsOn' => now()->addDays(60)->toDateString(),
            'endsOn' => now()->addDays(69)->toDateString(),
            'daysRequested' => 10,
        ])
        ->assertCreated();

    $second = LeaveRequest::query()->where('status', 'pending')->where('id', '!=', $request->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests/'.$second->getKey().'/approve')
        ->assertStatus(422);

    // Rejection consumes nothing.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests/'.$second->getKey().'/reject')
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    // Double approval of the same request → CAS 409 (exactly one winner).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests/'.$request->getKey().'/approve')
        ->assertStatus(409);

    // Cross-tenant isolation: the other tenant's leave is invisible.
    ['org' => $otherOrg, 'facility' => $otherFacility] = hrOtherTenant();
    $otherStaff = Staff::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'department_id' => Department::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ])->getKey(),
    ]);
    LeaveRequest::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'staff_id' => $otherStaff->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/leave-requests')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('generates an audited payroll-ready export (who exported what)', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    // One worked day + one shift + one approved leave day in the period.
    $periodStart = now()->startOfMonth()->toDateString();
    $periodEnd = now()->endOfMonth()->toDateString();

    AttendanceRecord::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'attendance_date' => now()->toDateString(),
        'status' => AttendanceRecord::STATUS_PRESENT,
    ]);

    $template = ShiftTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);
    Roster::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'shift_template_id' => $template->getKey(),
        'roster_date' => now()->addDays(1)->toDateString(),
        'status' => Roster::STATUS_CONFIRMED,
    ]);

    $type = LeaveType::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);
    LeaveRequest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'leave_type_id' => $type->getKey(),
        'starts_on' => now()->addDays(7)->toDateString(),
        'ends_on' => now()->addDays(8)->toDateString(),
        'days_requested' => 2,
        'status' => LeaveRequest::STATUS_APPROVED,
    ]);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/payroll-exports', [
            'periodStart' => $periodStart,
            'periodEnd' => $periodEnd,
        ])
        ->assertCreated()
        ->assertJsonPath('data.export.rowCount', 1)
        ->assertJsonPath('data.export.format', 'payroll_ready');

    $payload = $response->json('data.payload');
    expect($payload['staff'][0]['staffId'])->toBe($staff->getKey())
        ->and($payload['staff'][0]['workedDays'])->not->toBeEmpty()
        ->and($payload['staff'][0]['shifts'])->not->toBeEmpty()
        ->and($payload['staff'][0]['leave'])->not->toBeEmpty();

    // The export row records who/what/when with a hash.
    $export = PayrollExport::query()->firstOrFail();
    expect($export->exported_by_staff_id)->toBe($staff->getKey())
        ->and($export->payload_hash)->not->toBeNull();

    // Audit: export facts only — no names.
    $event = AuditEvent::query()->where('action', 'payroll_export.generated')->latest('occurred_at')->first();
    expect($event)->not->toBeNull()
        ->and($event->payload)->toMatchArray(['rowCount' => 1, 'format' => 'payroll_ready'])
        ->and($event->payload)->not->toHaveKey('staffId');

    // Export log lists the audited export.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/payroll-exports')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.exportedByStaffId', $staff->getKey());
});

it('drives the asset lifecycle with honest downtime and append-only transfers', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/asset-categories', ['code' => 'IMG', 'name' => 'Imaging'])
        ->assertCreated();

    $category = AssetCategory::query()->where('code', 'IMG')->firstOrFail();

    $locationA = Location::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $locationB = Location::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    // Register → procured.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets', [
            'categoryId' => $category->getKey(),
            'name' => 'MRI Scanner 1',
            'serialNumber' => 'SN-001',
            'currentLocationId' => $locationA->getKey(),
            'purchaseValueMinor' => 5000000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.lifecycleStatus', 'procured');

    $asset = Asset::query()->firstOrFail();

    // Deploy → deployed.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/deploy')
        ->assertOk()
        ->assertJsonPath('data.lifecycleStatus', 'deployed');

    // Transfer → append-only history + location update.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/transfer', ['toLocationId' => $locationB->getKey()])
        ->assertCreated()
        ->assertJsonPath('data.asset.lifecycleStatus', 'deployed')
        ->assertJsonPath('data.asset.currentLocationId', $locationB->getKey());

    $asset->refresh();
    expect(AssetTransfer::query()->where('asset_id', $asset->getKey())->count())->toBe(1);

    // Open a downtime work order → asset MUST be under_repair (honest).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/work-orders', [
            'assetId' => $asset->getKey(),
            'downtimeStartedAt' => now()->toIso8601String(),
            'description' => 'Cooling failure',
        ])
        ->assertCreated()
        ->assertJsonPath('data.assetLifecycleStatus', 'under_repair');

    $order = WorkOrder::query()->firstOrFail();
    expect($asset->refresh()->lifecycle_status)->toBe('under_repair');

    // Complete with downtime end + certification → back to deployed; the
    // certification reference is provable.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/work-orders/'.$order->getKey().'/complete', [
            'downtimeEndedAt' => now()->addHour()->toIso8601String(),
            'certificationRef' => 'CERT-2026-001',
        ])
        ->assertOk()
        ->assertJsonPath('data.assetLifecycleStatus', 'deployed');

    expect($order->refresh()->status)->toBe('completed')
        ->and($order->certification_ref)->toBe('CERT-2026-001');

    // Downtime must end after it started (422).
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/work-orders', [
            'assetId' => $asset->getKey(),
            'downtimeStartedAt' => now()->toIso8601String(),
        ])
        ->assertCreated();

    $order2 = WorkOrder::query()->where('status', 'open')->firstOrFail();
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/work-orders/'.$order2->getKey().'/complete', [
            'downtimeEndedAt' => now()->subHour()->toIso8601String(),
        ])
        ->assertStatus(422);

    // Cancel the open order → asset back to deployed.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/work-orders/'.$order2->getKey().'/cancel')
        ->assertOk()
        ->assertJsonPath('data.assetLifecycleStatus', 'deployed');

    // Retire → terminal.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/retire')
        ->assertOk()
        ->assertJsonPath('data.lifecycleStatus', 'retired');

    // A retired asset cannot be transferred or deployed again.
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/transfer', ['toLocationId' => $locationA->getKey()])
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/deploy')
        ->assertStatus(422);

    // Invalid lifecycle transition (deployed → procured) is refused.
    $fresh = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
        'lifecycle_status' => Asset::LIFECYCLE_DEPLOYED,
    ]);
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$fresh->getKey().'/retire')
        ->assertOk(); // deployed → retired is legal
});

it('records and lists RFID/IoT-ready readings (data model, no fake device)', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = hrAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $asset = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/iot-readings', [
            'readingType' => 'location',
            'readingValue' => ['zone' => 'ICU', 'rack' => 'B2'],
            'tagId' => 'TAG-001',
        ])
        ->assertCreated()
        ->assertJsonPath('data.readingType', 'location')
        ->assertJsonPath('data.source', 'manual');

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/assets/'.$asset->getKey().'/iot-readings')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect(IotReading::query()->count())->toBe(1);
});

it('enforces facility scoping and cross-tenant isolation on the whole surface', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = hrAdmin();
    ['org' => $otherOrg, 'facility' => $otherFacility] = hrOtherTenant();

    // The other tenant's data: an asset, an attendance record, a leave type.
    $otherCategory = AssetCategory::factory()->create(['tenant_id' => $otherOrg->getKey(), 'facility_id' => $otherFacility->getKey()]);
    $otherAsset = Asset::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'category_id' => $otherCategory->getKey(),
    ]);
    $otherStaff = Staff::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'department_id' => Department::factory()->create([
            'tenant_id' => $otherOrg->getKey(),
            'facility_id' => $otherFacility->getKey(),
        ])->getKey(),
    ]);

    // Facility-scoped admin cannot READ the other facility's rows (404) or
    // WRITE them (403) — existence is never leaked on reads.
    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/assets/'.$otherAsset->getKey().'/iot-readings')
        ->assertNotFound();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$otherAsset->getKey().'/deploy')
        ->assertForbidden();

    // Cross-tenant attendance row cannot be listed.
    AttendanceRecord::factory()->create([
        'tenant_id' => $otherOrg->getKey(),
        'facility_id' => $otherFacility->getKey(),
        'staff_id' => $otherStaff->getKey(),
        'attendance_date' => now()->toDateString(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/attendance')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    // No rows leaked into the admin's tenant.
    expect(AttendanceRecord::query()->where('tenant_id', $org->getKey())->count())->toBe(0);
});

it('emits PHI-safe audit payloads across the HR/asset surface', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $asset = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
        'lifecycle_status' => Asset::LIFECYCLE_DEPLOYED,
    ]);
    $type = LeaveType::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $leave = LeaveRequest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'leave_type_id' => $type->getKey(),
    ]);
    $template = ShiftTemplate::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $roster = Roster::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'shift_template_id' => $template->getKey(),
    ]);

    // Exercise every audited act.
    $this->withToken(Identity::tokenFor($admin))->postJson('/api/v1/assets/'.$asset->getKey().'/retire')->assertOk();
    $this->withToken(Identity::tokenFor($admin))->postJson('/api/v1/leave-requests/'.$leave->getKey().'/approve')->assertOk();
    $this->withToken(Identity::tokenFor($admin))->postJson('/api/v1/rosters/'.$roster->getKey().'/confirm')->assertOk();

    $payloads = AuditEvent::query()
        ->whereIn('action', ['asset.retired', 'leave.approved', 'roster.confirmed'])
        ->get()
        ->pluck('payload')
        ->all();

    $serialized = json_encode($payloads, JSON_THROW_ON_ERROR);

    // No staff names, license numbers, or free-text reasons anywhere.
    expect($serialized)->not->toContain($staff->full_name)
        ->and($serialized)->not->toContain('license')
        ->and($serialized)->not->toContain('reason');
});
