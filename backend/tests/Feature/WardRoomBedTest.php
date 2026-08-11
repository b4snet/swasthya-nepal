<?php

use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Room;
use App\Models\Ward;
use App\Support\BedStatus;
use Tests\Support\Identity;

/**
 * Wards → rooms → beds (DATABASE.md §3.24–3.26): hierarchy integrity, code
 * uniqueness, the bed status state machine, optimistic locking, and delete
 * restrictions.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('builds the ward → room → bed chain inside one facility', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $ward = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'code' => 'ward-1']);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/wards/'.$ward->getKey().'/rooms', [
            'name' => 'Room 101', 'code' => 'room-101', 'roomType' => 'general', 'dailyRateMinor' => 5000,
        ])
        ->assertCreated()
        ->assertJsonPath('data.wardId', $ward->getKey())
        ->assertJsonPath('data.facilityId', $facility->getKey());

    $room = Room::query()->firstOrFail();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rooms/'.$room->getKey().'/beds', ['bedCode' => 'B-1'])
        ->assertCreated()
        ->assertJsonPath('data.status', BedStatus::AVAILABLE)
        ->assertJsonPath('data.roomId', $room->getKey());

    expect(Bed::query()->count())->toBe(1);
});

it('enforces bed code uniqueness per room, not per facility', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $ward = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $roomA = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'ward_id' => $ward->getKey(), 'code' => 'room-a']);
    $roomB = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'ward_id' => $ward->getKey(), 'code' => 'room-b']);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rooms/'.$roomA->getKey().'/beds', ['bedCode' => 'B-1'])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rooms/'.$roomA->getKey().'/beds', ['bedCode' => 'b-1'])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/rooms/'.$roomB->getKey().'/beds', ['bedCode' => 'B-1'])
        ->assertCreated();
});

it('validates bed status transitions as a state machine', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $ward = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $room = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'ward_id' => $ward->getKey()]);
    $bed = Bed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'room_id' => $room->getKey(), 'status' => BedStatus::AVAILABLE]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    // Legal: available → reserved → cleaning → available.
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'reserved', 'lockVersion' => 0])
        ->assertOk()
        ->assertJsonPath('data.status', 'reserved')
        ->assertJsonPath('data.lockVersion', 1);

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'cleaning', 'lockVersion' => 1])
        ->assertOk();

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'available', 'lockVersion' => 2])
        ->assertOk();

    // Illegal: cleaning → out_of_service is allowed, but cleaning → cleaning
    // is not a transition at all; occupied is admission-only (Phase 8).
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'occupied', 'lockVersion' => 3])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'available', 'lockVersion' => 3])
        ->assertStatus(422);

    expect(Bed::query()->findOrFail($bed->getKey())->status)->toBe('available');
});

it('audits every bed state change with from and to', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $ward = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $room = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'ward_id' => $ward->getKey()]);
    $bed = Bed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'room_id' => $room->getKey(), 'status' => BedStatus::AVAILABLE]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'out_of_service', 'lockVersion' => 0])
        ->assertOk();

    $event = AuditEvent::query()->where('action', 'bed.status.changed')->firstOrFail();
    expect($event->resource_id)->toBe($bed->getKey())
        // jsonb reorders keys — assert fields individually.
        ->and($event->payload['from'])->toBe('available')
        ->and($event->payload['to'])->toBe('out_of_service')
        ->and($event->payload['lockVersion'])->toBe(1)
        ->and($event->tenant_id)->toBe($org->getKey())
        ->and($event->facility_id)->toBe($facility->getKey());
});

it('rejects stale optimistic locks with 409 LOCK_CONFLICT', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $ward = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $room = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'ward_id' => $ward->getKey()]);
    $bed = Bed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'room_id' => $room->getKey(), 'status' => BedStatus::AVAILABLE]);
    $adminA = Identity::user(['email' => 'nurse-a@two.test']);
    $adminB = Identity::user(['email' => 'nurse-b@two.test']);
    Identity::assign($adminA, 'hospital_admin', $org, $facility);
    Identity::assign($adminB, 'hospital_admin', $org, $facility);

    // A moves the bed first (lock_version 0 → 1).
    $this->withToken(Identity::tokenFor($adminA))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'reserved', 'lockVersion' => 0])
        ->assertOk();

    // B still holds the stale lock_version → 409, nothing overwritten.
    $this->withToken(Identity::tokenFor($adminB))
        ->patchJson('/api/v1/beds/'.$bed->getKey(), ['status' => 'cleaning', 'lockVersion' => 0])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LOCK_CONFLICT');

    expect(Bed::query()->findOrFail($bed->getKey())->status)->toBe('reserved');
});

it('prevents deleting a ward with rooms and a room with beds', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $ward = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $room = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'ward_id' => $ward->getKey()]);
    $bed = Bed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey(), 'room_id' => $room->getKey()]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/wards/'.$ward->getKey())
        ->assertStatus(409);

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/rooms/'.$room->getKey())
        ->assertStatus(409);

    // Beds are never deleted — they only change status.
    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/beds/'.$bed->getKey())
        ->assertStatus(405);
});

it('scopes the bed list to the caller facility', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    $wardA = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey()]);
    $wardB = Ward::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey()]);
    $roomA = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(), 'ward_id' => $wardA->getKey()]);
    $roomB = Room::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(), 'ward_id' => $wardB->getKey()]);
    Bed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityA->getKey(), 'room_id' => $roomA->getKey()]);
    Bed::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(), 'room_id' => $roomB->getKey()]);

    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/organizations/'.$org->getKey().'/beds')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
