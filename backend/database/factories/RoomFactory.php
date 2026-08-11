<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Ward::query()->findOrFail($attributes['ward_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Ward::query()->findOrFail($attributes['ward_id'])->facility_id,
            'ward_id' => WardFactory::new(),
            'name' => fake()->randomElement(['Room 101', 'Room 102', 'Private Room A']),
            'code' => 'room-'.Str::lower(Str::random(5)),
            'room_type' => 'general',
            'daily_rate_minor' => null,
            'currency' => null,
            'status' => Room::STATUS_ACTIVE,
        ];
    }
}
