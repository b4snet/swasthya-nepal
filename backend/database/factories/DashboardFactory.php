<?php

namespace Database\Factories;

use App\Models\Dashboard;
use App\Models\Facility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dashboard>
 */
class DashboardFactory extends Factory
{
    protected $model = Dashboard::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'code' => fn (): string => 'dash-'.substr((string) Str::uuid(), 0, 8),
            'name' => 'Operational Dashboard',
            'role_gate' => ['hospital_admin'],
            'is_active' => true,
            'lock_version' => 0,
        ];
    }
}
