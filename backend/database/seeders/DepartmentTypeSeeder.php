<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Seeds the default SWASTHYA hospital department catalog.
 *
 * These are pre-configured templates that administrators can activate
 * during hospital onboarding. The actual departments are created per
 * facility — this seeder provides the type definitions.
 */
class DepartmentTypeSeeder extends Seeder
{
    public function run(): void
    {
        // The department types are defined as constants on the Department model.
        // This seeder documents them and can be extended for bulk-creation
        // during onboarding.
        //
        // Medical departments (Department::MEDICAL_DEPARTMENTS):
        //   gynecology_obstetrics, psychiatry, neurology, cardiology,
        //   pediatrics, rheumatology, internal_medicine, dermatology,
        //   nephrology, gastroenterology, acupuncture, endocrinology,
        //   ophthalmology, ent, urology, oncology, pulmonology, hematology
        //
        // Surgical departments (Department::SURGICAL_DEPARTMENTS):
        //   general_surgery, cardiovascular_surgery, pediatric_surgery,
        //   spine_surgery, neurosurgery, plastic_surgery, orthopedic_surgery,
        //   gi_laparoscopic, dental
        //
        // Supportive departments (Department::SUPPORTIVE_DEPARTMENTS):
        //   radiology_imaging, physiotherapy, vaccination, laboratory,
        //   dietician, pharmacy

        // Ensure the constants are accessible (prevents dead-code lint)
        $medicalCount = count(Department::MEDICAL_DEPARTMENTS);
        $surgicalCount = count(Department::SURGICAL_DEPARTMENTS);
        $supportiveCount = count(Department::SUPPORTIVE_DEPARTMENTS);

        $this->command?->info("Department catalog: {$medicalCount} medical, {$surgicalCount} surgical, {$supportiveCount} supportive departments defined.");
    }
}
