<?php

namespace Database\Factories;

use App\Models\Admission;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\MarEntry;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarEntry>
 */
class MarEntryFactory extends Factory
{
    protected $model = MarEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->tenant_id,
            'facility_id' => fn (array $attributes): string => Admission::query()->findOrFail($attributes['admission_id'])->facility_id,
            'admission_id' => fn (): string => Admission::factory()->create()->getKey(),
            // A prescription line in the same tenant: the prescription is
            // created against an encounter in the admission's tenant, so the
            // composite FK (tenant_id, prescription_line_id) lines up. Tests
            // override with a line for the admission's own patient.
            'prescription_line_id' => fn (array $attributes): string => self::lineIn($attributes['tenant_id']),
            'scheduled_at' => now()->addHour()->startOfMinute(),
            'status' => MarEntry::STATUS_SCHEDULED,
            'administered_by' => null,
            'administered_at' => null,
            'reason' => null,
        ];
    }

    private static function lineIn(string $tenantId): string
    {
        $facility = Facility::factory()->create(['tenant_id' => $tenantId]);
        $encounter = Encounter::factory()->create([
            'tenant_id' => $tenantId,
            'facility_id' => $facility->getKey(),
        ]);
        $prescription = Prescription::factory()->create([
            'tenant_id' => $tenantId,
            'encounter_id' => $encounter->getKey(),
        ]);

        return PrescriptionLine::factory()->create([
            'tenant_id' => $tenantId,
            'prescription_id' => $prescription->getKey(),
            'status' => PrescriptionLine::STATUS_ORDERED,
        ])->getKey();
    }
}
