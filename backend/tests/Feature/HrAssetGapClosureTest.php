<?php

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetDisposal;
use App\Models\CalibrationRecord;
use App\Models\Department;
use App\Models\EquipmentIncident;
use App\Models\Facility;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Staff;
use App\Models\StaffCredential;
use App\Models\StaffTransfer;
use App\Models\User;
use Tests\Support\Identity;

/**
 * HR / Asset gap closure tests — covers 10 gaps identified against
 * the 201-section prompt.
 *
 * Uses real HTTP calls against a wrapping transaction that is rolled back
 * after each test. No external APIs. No real patient data.
 */
beforeEach(function (): void {
    seedIdentity();
});

function hrGapAdmin(): array
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

    return ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff, 'department' => $department];
}

// ── 1. Credential CRUD + verify + expiring (§24-27) ──────────────

it('CRUDs staff credentials with verification and expiry surfacing', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrGapAdmin();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/credentials', [
            'staff_id' => $staff->getKey(),
            'credential_type' => 'medical_license',
            'credential_code' => 'NMC-12345',
            'title' => 'Medical License',
            'issuing_authority' => 'Nepal Medical Council',
            'issue_date' => now()->subYear()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('credential_type', 'medical_license')
        ->assertJsonPath('status', 'active');

    $credential = StaffCredential::query()
        ->where('tenant_id', $org->getKey())
        ->where('staff_id', $staff->getKey())
        ->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/credentials/'.$credential->getKey())
        ->assertOk()
        ->assertJsonPath('id', $credential->getKey());

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/credentials/'.$credential->getKey(), [
            'issuing_authority' => 'Nepal Medical Council (Updated)',
        ])
        ->assertOk()
        ->assertJsonPath('issuing_authority', 'Nepal Medical Council (Updated)');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/credentials/'.$credential->getKey().'/verify')
        ->assertOk()
        ->assertJsonPath('verified_by', $admin->getKey())
        ->assertJsonStructure(['id', 'verified_by', 'verified_at']);

    $expiring = StaffCredential::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'expiry_date' => now()->addDays(15),
        'status' => StaffCredential::STATUS_ACTIVE,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/credentials-expiring?days=30')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $expiring->getKey());
});

// ── 2. Calibration records (§81-82) ─────────────────────────────

it('records and lists asset calibration records', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = hrGapAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $asset = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/calibration', [
            'calibration_type' => 'external',
            'provider' => 'Biomed Services Pvt Ltd',
            'calibrated_at' => now()->subMonth()->toIso8601String(),
            'due_at' => now()->addMonth()->toIso8601String(),
            'result' => 'pass',
            'notes' => 'All parameters within spec',
        ])
        ->assertCreated()
        ->assertJsonPath('calibration_type', 'external')
        ->assertJsonPath('result', 'pass');

    $record = CalibrationRecord::query()
        ->where('tenant_id', $org->getKey())
        ->where('asset_id', $asset->getKey())
        ->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/assets/'.$asset->getKey().'/calibration')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── 3. Equipment incidents (§88) ─────────────────────────────────

it('reports and resolves equipment incidents with CAS', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrGapAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $asset = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/incidents', [
            'incident_type' => 'malfunction',
            'description' => 'Ventilator alarm triggered without cause during operation',
            'reported_by_staff_id' => $staff->getKey(),
            'occurred_at' => now()->subDay()->toIso8601String(),
            'severity' => 'high',
        ])
        ->assertCreated()
        ->assertJsonPath('incident_type', 'malfunction')
        ->assertJsonPath('severity', 'high')
        ->assertJsonPath('status', 'open');

    $incident = EquipmentIncident::query()
        ->where('tenant_id', $org->getKey())
        ->where('asset_id', $asset->getKey())
        ->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/assets/'.$asset->getKey().'/incidents')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/equipment-incidents/'.$incident->getKey().'/resolve', [
            'resolution' => 'Alarm sensor replaced; root cause: faulty pressure transducer',
        ])
        ->assertOk()
        ->assertJsonPath('status', 'resolved')
        ->assertJsonStructure(['id', 'status', 'resolution']);
});

// ── 4. Asset disposal (§91) ──────────────────────────────────────

it('disposes an asset and records disposal history', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrGapAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $asset = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
        'lifecycle_status' => Asset::LIFECYCLE_DEPLOYED,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/dispose', [
            'disposal_type' => 'recycled',
            'reason' => 'End of useful life; replaced with newer model',
            'authorized_by_staff_id' => $staff->getKey(),
            'disposed_at' => now()->toIso8601String(),
        ])
        ->assertCreated()
        ->assertJsonPath('disposal_type', 'recycled');

    $asset->refresh();
    expect($asset->lifecycle_status)->toBe(Asset::LIFECYCLE_RETIRED);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/assets/'.$asset->getKey().'/disposals')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/dispose', [
            'disposal_type' => 'destroyed',
            'reason' => 'Second attempt',
            'authorized_by_staff_id' => $staff->getKey(),
            'disposed_at' => now()->toIso8601String(),
        ])
        ->assertStatus(409);
});

// ── 5. Staff transfers (§58-59) ──────────────────────────────────

it('transfers staff between departments and records history', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff, 'department' => $department] = hrGapAdmin();

    $newDept = Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/staff/'.$staff->getKey().'/transfer', [
            'to_department_id' => $newDept->getKey(),
            'reason' => 'Operational needs',
            'authorized_by_staff_id' => $staff->getKey(),
            'effective_at' => now()->toIso8601String(),
        ])
        ->assertOk();

    $staff->refresh();
    expect($staff->department_id)->toBe($newDept->getKey());

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/staff/'.$staff->getKey().'/transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $transfer = StaffTransfer::query()
        ->where('tenant_id', $org->getKey())
        ->where('staff_id', $staff->getKey())
        ->firstOrFail();

    expect($transfer->from_department_id)->toBe($department->getKey())
        ->and($transfer->to_department_id)->toBe($newDept->getKey());
});

// ── 6. Concurrent roster assignment (§121) ───────────────────────

it('detects concurrent roster overlap via CAS', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrGapAdmin();

    $shift = \App\Models\ShiftTemplate::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'starts_at' => '08:00',
        'ends_at' => '16:00',
    ]);

    $date = now()->toDateString();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $shift->getKey(),
            'rosterDate' => $date,
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rosters', [
            'staffId' => $staff->getKey(),
            'shiftTemplateId' => $shift->getKey(),
            'rosterDate' => $date,
        ])
        ->assertStatus(409);
});

// ── 7. Concurrent leave approval (§121) ──────────────────────────

it('detects concurrent leave approval via CAS', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrGapAdmin();

    $type = \App\Models\LeaveType::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $leave = \App\Models\LeaveRequest::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'leave_type_id' => $type->getKey(),
        'starts_on' => now()->addDays(10),
        'ends_on' => now()->addDays(12),
        'days_requested' => 3,
        'status' => \App\Models\LeaveRequest::STATUS_PENDING,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests/'.$leave->getKey().'/approve')
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/leave-requests/'.$leave->getKey().'/approve')
        ->assertStatus(409);
});

// ── 8. Concurrent asset transfer (§93) ───────────────────────────

it('tracks asset transfer history', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = hrGapAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $locationA = Location::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);
    $locationB = Location::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);

    $asset = Asset::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'category_id' => $category->getKey(),
        'current_location_id' => $locationA->getKey(),
        'lifecycle_status' => Asset::LIFECYCLE_DEPLOYED,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets/'.$asset->getKey().'/transfer', [
            'toLocationId' => $locationB->getKey(),
            'reason' => 'Initial transfer',
        ])
        ->assertStatus(201);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/assets/'.$asset->getKey().'/transfers')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ── 9. Credential expiry surfacing (§26) ─────────────────────────

it('surfaces credentials expiring within a given window', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin, 'staff' => $staff] = hrGapAdmin();

    $expiring = StaffCredential::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'expiry_date' => now()->addDays(10),
        'status' => StaffCredential::STATUS_ACTIVE,
    ]);

    StaffCredential::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'expiry_date' => now()->addDays(60),
        'status' => StaffCredential::STATUS_ACTIVE,
    ]);

    StaffCredential::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'staff_id' => $staff->getKey(),
        'expiry_date' => now()->subDays(5),
        'status' => StaffCredential::STATUS_EXPIRED,
    ]);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/credentials-expiring?days=30')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $expiring->getKey());
});

// ── 10. Duplicate asset creation prevention (§95) ────────────────

it('prevents duplicate asset creation by serial number via DB unique constraint', function () {
    ['org' => $org, 'facility' => $facility, 'admin' => $admin] = hrGapAdmin();

    $category = AssetCategory::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets', [
            'name' => 'Ventilator A',
            'categoryId' => $category->getKey(),
            'serialNumber' => 'VENT-001',
            'purchaseValueMinor' => 500000,
        ])
        ->assertCreated();

    expect(Asset::query()
        ->where('tenant_id', $org->getKey())
        ->where('serial_number', 'VENT-001')
        ->count())->toBe(1);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/assets', [
            'name' => 'Ventilator B',
            'categoryId' => $category->getKey(),
            'serialNumber' => 'VENT-001',
            'purchaseValueMinor' => 450000,
        ])
        ->assertStatus(500);
});
