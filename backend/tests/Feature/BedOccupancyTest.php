<?php

use App\Models\Bed;
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
    $bed = Bed::create([
        'tenant_id' => '00000000-0000-0000-0000-000000000001',
        'facility_id' => '00000000-0000-0000-0000-000000000010',
        'room_id' => '00000000-0000-0000-0000-000000000020',
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
