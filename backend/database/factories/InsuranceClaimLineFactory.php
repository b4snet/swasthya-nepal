<?php

namespace Database\Factories;

use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimLine;
use App\Models\InvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InsuranceClaimLine>
 */
class InsuranceClaimLineFactory extends Factory
{
    protected $model = InsuranceClaimLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $claimId = fn (array $attributes): string => $attributes['claim_id']
            ?? InsuranceClaim::factory()->create()->getKey();

        return [
            'tenant_id' => fn (array $attributes): string => InsuranceClaim::query()->findOrFail($claimId($attributes))->tenant_id,
            'claim_id' => $claimId,
            'invoice_line_id' => fn (array $attributes): string => InvoiceLine::factory()->create([
                'invoice_id' => fn (): string => InsuranceClaim::query()->findOrFail($claimId($attributes))->invoice_id,
            ])->getKey(),
            'billed_minor' => fn (array $attributes): int => (int) InvoiceLine::query()->findOrFail($attributes['invoice_line_id'])->amount_minor,
            'status' => InsuranceClaimLine::STATUS_PENDING,
        ];
    }
}
