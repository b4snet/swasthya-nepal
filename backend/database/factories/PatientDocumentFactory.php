<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\PatientDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PatientDocument>
 */
class PatientDocumentFactory extends Factory
{
    protected $model = PatientDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => fn (array $attributes): string => Patient::query()->findOrFail($attributes['patient_id'])->tenant_id,
            'patient_id' => PatientFactory::new(),
            'document_type' => 'id',
            'status' => PatientDocument::STATUS_STAGED,
            'mime_type' => 'application/pdf',
        ];
    }
}
