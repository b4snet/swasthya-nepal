<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\Facility;
use App\Models\Patient;
use App\Models\RefundRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefundRequest>
 */
class RefundRequestFactory extends Factory
{
    protected $model = RefundRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $facilityId = fn (array $attributes): string => $attributes['facility_id']
            ?? Facility::factory()->create()->getKey();

        return [
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($facilityId($attributes))->tenant_id,
            'facility_id' => $facilityId,
            'patient_id' => fn (array $attributes): string => Patient::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
            ])->getKey(),
            'charge_id' => fn (array $attributes): string => Charge::factory()->create([
                'tenant_id' => $attributes['tenant_id'],
                'facility_id' => $attributes['facility_id'],
                'patient_id' => $attributes['patient_id'],
            ])->getKey(),
            'amount_minor' => fn (array $attributes): int => (int) round(Charge::query()->findOrFail($attributes['charge_id'])->amount_minor / 2),
            'reason_code' => RefundRequest::REASON_OVERCHARGE,
            'reason_note' => null,
            'status' => RefundRequest::STATUS_REQUESTED,
            'lock_version' => 0,
        ];
    }
}
