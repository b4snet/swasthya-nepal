<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Care',
            'code' => 'org-'.Str::lower(Str::random(6)),
            'status' => Organization::STATUS_ACTIVE,
            'currency' => 'NPR',
            'timezone' => 'Asia/Kathmandu',
            'locale' => 'en',
            'tax_config' => [],
            'settings' => [],
        ];
    }
}
