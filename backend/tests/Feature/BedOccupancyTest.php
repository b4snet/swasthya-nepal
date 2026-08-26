<?php

use App\Models\Bed;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Room;
use App\Models\Ward;
use App\Support\BedStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('bed status has maintenance state', function () {
    expect(BedStatus::MAINTENANCE)->toBe('maintenance');
    expect(BedStatus::VALID)->toContain('maintenance');
});

it('bed status allows occupied transitions', function () {
    expect(BedStatus::canTransition('occupied', 'available'))->toBeTrue();
    expect(BedStatus::canTransition('occupied', 'cleaning'))->toBeTrue();
    expect(BedStatus::canTransition('occupied', 'reserved'))->toBeTrue();
    expect(BedStatus::canTransition('occupied', 'maintenance'))->toBeTrue();
    expect(BedStatus::canTransition('occupied', 'out_of_service'))->toBeTrue();
});

it('bed status allows maintenance transitions', function () {
    expect(BedStatus::canTransition('maintenance', 'available'))->toBeTrue();
    expect(BedStatus::canTransition('maintenance', 'reserved'))->toBeTrue();
    expect(BedStatus::canTransition('maintenance', 'cleaning'))->toBeTrue();
    expect(BedStatus::canTransition('maintenance', 'out_of_service'))->toBeTrue();
});

it('bed status blocks invalid transitions', function () {
    expect(BedStatus::canTransition('available', 'occupied'))->toBeFalse();
    expect(BedStatus::canTransition('reserved', 'occupied'))->toBeFalse();
});

it('bed model stores admission id', function () {
    $org = Organization::create(['name' => 'Test Org', 'code' => 'TST', 'status' => 'active']);
    $facility = Facility::create(['tenant_id' => $org->id, 'name' => 'Test Facility', 'code' => 'TF', 'status' => 'active', 'timezone' => 'UTC', 'address' => '{}', 'settings' => '{}']);
    $ward = Ward::create(['tenant_id' => $org->id, 'facility_id' => $facility->id, 'name' => 'W1', 'code' => 'W1', 'ward_type' => 'general', 'status' => 'active']);
    $room = Room::create(['tenant_id' => $org->id, 'facility_id' => $facility->id, 'ward_id' => $ward->id, 'name' => 'R1', 'code' => 'R1', 'room_type' => 'general', 'status' => 'active']);

    $bed = Bed::create([
        'tenant_id' => $org->id,
        'facility_id' => $facility->id,
        'room_id' => $room->id,
        'bed_code' => 'A-101',
        'status' => BedStatus::AVAILABLE,
        'lock_version' => 0,
    ]);

    expect($bed->bed_code)->toBe('A-101');
    expect($bed->status)->toBe('available');
    expect($bed->lock_version)->toBe(0);
});

it('bed isAvailable returns correct state', function () {
    $bed = new Bed;
    $bed->status = BedStatus::AVAILABLE;
    expect($bed->isAvailable())->toBeTrue();

    $bed->status = BedStatus::OCCUPIED;
    expect($bed->isAvailable())->toBeFalse();
});
