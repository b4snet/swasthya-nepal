<?php

namespace Database\Factories;

use App\Models\Bed;
use App\Models\Room;
use App\Support\BedStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Bed>
 */
class BedFactory extends Factory
{
    protected $model = Bed::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Room::query()->findOrFail($attributes['room_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Room::query()->findOrFail($attributes['room_id'])->facility_id,
            'room_id' => RoomFactory::new(),
            'bed_code' => 'B'.Str::upper(Str::random(3)),
            'status' => BedStatus::AVAILABLE,
            'lock_version' => 0,
        ];
    }
}
