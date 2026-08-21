<?php

namespace Database\Factories;

use App\Models\Facility;
use App\Models\GeneratedDocument;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeneratedDocument>
 */
class GeneratedDocumentFactory extends Factory
{
    protected $model = GeneratedDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = $this->faker->randomElement(array_keys(GeneratedDocument::types()));
        $category = $this->faker->randomElement(array_keys(GeneratedDocument::categories()));

        return [
            'tenant_id' => Organization::factory(),
            'facility_id' => Facility::factory(),
            'document_number' => strtoupper($type).'-'.$this->faker->year().'-'.$this->faker->numerify('#####'),
            'document_type' => $type,
            'category' => $category,
            'title' => $this->faker->sentence(3),
            'content_html' => '<p>'.$this->faker->paragraph().'</p>',
            'content_text' => $this->faker->paragraph(),
            'status' => GeneratedDocument::STATUS_GENERATED,
            'verified' => false,
            'signed' => false,
            'printable' => true,
            'pdf_capable' => true,
            'visibility' => 'staff',
            'shared_with_patient' => false,
        ];
    }
}
