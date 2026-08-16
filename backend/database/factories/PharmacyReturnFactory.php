<?php

namespace Database\Factories;

use App\Models\Charge;
use App\Models\PharmacyReturn;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PharmacyReturn>
 */
class PharmacyReturnFactory extends Factory
{
    protected $model = PharmacyReturn::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $chargeId = fn (array $attributes): string => $attributes['charge_id']
            ?? Charge::factory()->create()->getKey();

        $lineId = fn (array $attributes): string => $attributes['prescription_line_id']
            ?? (string) Charge::query()->findOrFail($chargeId($attributes))->prescription_line_id;

        return [
            'tenant_id' => fn (array $attributes): string => Charge::query()->findOrFail($chargeId($attributes))->tenant_id,
            'facility_id' => fn (array $attributes): string => Charge::query()->findOrFail($chargeId($attributes))->facility_id,
            'prescription_line_id' => $lineId,
            'prescription_id' => fn (array $attributes): string => Prescription::query()
                ->findOrFail(PrescriptionLine::query()->findOrFail($lineId($attributes))->prescription_id)->getKey(),
            'charge_id' => $chargeId,
            'quantity_minor' => fn (array $attributes): int => max(1, (int) (PrescriptionLine::query()
                ->findOrFail($lineId($attributes))->quantity_minor ?? 1)),
            'reason_code' => PharmacyReturn::REASON_PATIENT_RETURN,
            'reason_note' => null,
            'returned_at' => now(),
        ];
    }
}
