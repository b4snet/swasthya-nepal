<?php

namespace Database\Factories;

use App\Models\ImageReference;
use Illuminate\Database\Eloquent\Factories\Factory;

class ImageReferenceFactory extends Factory
{
    protected $model = ImageReference::class;

    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'facility_id' => $this->faker->uuid(),
            'study_id' => $this->faker->uuid(),
            'reference_type' => $this->faker->randomElement([
                'dicom_study_instance_uid',
                'dicom_series_instance_uid',
                'dicom_sop_instance_uid',
                'pacs_url',
            ]),
            'reference_value' => $this->faker->uuid(),
            'series_instance_uid' => $this->faker->uuid(),
            'sop_instance_uid' => $this->faker->uuid(),
            'description' => $this->faker->sentence(),
        ];
    }
}