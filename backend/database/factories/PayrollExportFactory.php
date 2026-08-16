<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\PayrollExport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PayrollExport>
 */
class PayrollExportFactory extends Factory
{
    protected $model = PayrollExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'facility_id' => fn (): string => Facility::factory()->create()->getKey(),
            'tenant_id' => fn (array $attributes): string => Facility::query()->findOrFail($attributes['facility_id'])->tenant_id,
            'period_start' => fn (): string => now()->startOfMonth()->toDateString(),
            'period_end' => fn (): string => now()->endOfMonth()->toDateString(),
            'row_count' => 0,
            'format' => PayrollExport::FORMAT_PAYROLL_READY,
            'payload_hash' => Str::random(64),
            'exported_at' => fn (): string => now()->toIso8601String(),
        ];
    }
}
