<?php

namespace Database\Factories;

use App\Models\Payer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payer>
 */
class PayerFactory extends Factory
{
    protected $model = Payer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => OrganizationFactory::new(),
            'name' => fake()->company().' Insurance',
            'code' => 'pay-'.Str::lower(Str::random(6)),
            'payer_type' => 'private',
            'status' => Payer::STATUS_ACTIVE,
        ];
    }
}
