<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InsuranceClaim>
 */
class InsuranceClaimFactory extends Factory
{
    protected $model = InsuranceClaim::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $policyId = fn (array $attributes): string => $attributes['policy_id']
            ?? InsurancePolicy::factory()->create()->getKey();
        $tenantId = fn (array $attributes): string => InsurancePolicy::query()->findOrFail($policyId($attributes))->tenant_id;

        return [
            'tenant_id' => $tenantId,
            'policy_id' => $policyId,
            'invoice_id' => fn (array $attributes): string => Invoice::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => fn (): string => Facility::factory()->create(['tenant_id' => $attributes['tenant_id']])->getKey(),
            ])->getKey(),
            'payer_id' => fn (array $attributes): string => InsurancePolicy::query()->findOrFail($policyId($attributes))->payer_id,
            'claim_number' => 'CLM-'.strtoupper(Str::random(10)),
            'status' => InsuranceClaim::STATUS_DRAFT,
            'lock_version' => 0,
        ];
    }
}
