<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Department;
use App\Models\Room;
use App\Models\Staff;
use App\Models\TransferEvent;
use App\Support\BedStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TransferEvent>
 */
class TransferEventFactory extends Factory
{
    protected $model = TransferEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->facility_id,
            'admission_id' => fn (): string => Admission::factory()->create()->getKey(),
            'from_bed_id' => fn (array $attributes): string => self::bedIn($attributes['tenant_id'], $attributes['facility_id']),
            'to_bed_id' => fn (array $attributes): string => self::bedIn($attributes['tenant_id'], $attributes['facility_id']),
            'reason' => fake()->sentence(4),
            'transferred_by' => fn (array $attributes): string => self::staffIn($attributes['tenant_id'], $attributes['facility_id']),
            'transferred_at' => now(),
        ];
    }

    /**
     * A bed in the same tenant+facility (rooms/beds composite-FK aligned).
     */
    private static function bedIn(string $tenantId, string $facilityId): string
    {
        $room = Room::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
        ]);

        return Bed::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'room_id' => $room->getKey(),
            'status' => BedStatus::AVAILABLE,
        ])->getKey();
    }

    /**
     * A staff record in the same tenant+facility (department composite-FK
     * aligned).
     */
    private static function staffIn(string $tenantId, string $facilityId): string
    {
        $department = Department::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
        ]);

        return Staff::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
            'department_id' => $department->getKey(),
            'status' => Staff::STATUS_ACTIVE,
        ])->getKey();
    }
}
