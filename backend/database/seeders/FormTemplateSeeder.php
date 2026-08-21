<?php

namespace Database\Seeders;

use App\Models\FormTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the comprehensive SWASTHYA clinical form library.
 *
 * Each template has a JSON schema defining its fields, appropriate
 * categorization, and role/module access rules.
 */
class FormTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = $this->getTemplates();

        foreach ($templates as $template) {
            FormTemplate::updateOrCreate(
                ['code' => $template['code']],
                $template,
            );
        }
    }

    private function getTemplates(): array
    {
        return array_merge(
            $this->registrationForms(),
            $this->clinicalForms(),
            $this->consentForms(),
            $this->specialtyForms(),
            $this->pediatricForms(),
            $this->mentalHealthForms(),
            $this->nutritionForms(),
            $this->dentalForms(),
            $this->imagingForms(),
            $this->laboratoryForms(),
            $this->admissionForms(),
            $this->icuForms(),
            $this->pharmacyForms(),
            $this->referralForms(),
            $this->insuranceForms(),
            $this->telemedicineForms(),
            $this->vitalsForms(),
            $this->nursingForms(),
        );
    }

    private function base(string $code, string $name, string $category, array $schema, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => $name,
            'slug' => Str::slug($name),
            'category' => $category,
            'schema' => $schema,
            'version' => 1,
            'is_active' => true,
            'is_published' => true,
            'printable' => true,
            'pdf_capable' => true,
            'linked_to_patient' => true,
            'generates_document_number' => true,
        ], $overrides);
    }

    private function field(string $key, string $label, string $type = 'text', array $opts = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => false,
        ], $opts);
    }

    // ════════════════════════════════════════════════════════════════
    //  REGISTRATION FORMS
    // ════════════════════════════════════════════════════════════════

    private function registrationForms(): array
    {
        $schema = [
            'sections' => [
                ['title' => 'Personal Information', 'fields' => [
                    $this->field('full_name', 'Full Name', 'text', ['required' => true]),
                    $this->field('date_of_birth', 'Date of Birth', 'date', ['required' => true]),
                    $this->field('sex', 'Sex', 'select', ['options' => ['Male', 'Female', 'Other'], 'required' => true]),
                    $this->field('blood_group', 'Blood Group', 'select', ['options' => ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']]),
                    $this->field('marital_status', 'Marital Status', 'select', ['options' => ['Single', 'Married', 'Divorced', 'Widowed']]),
                    $this->field('nationality', 'Nationality', 'text'),
                ]],
                ['title' => 'Contact Information', 'fields' => [
                    $this->field('phone', 'Phone Number', 'tel', ['required' => true]),
                    $this->field('email', 'Email', 'email'),
                    $this->field('address', 'Address', 'textarea'),
                    $this->field('city', 'City', 'text'),
                    $this->field('district', 'District', 'text'),
                ]],
                ['title' => 'Emergency Contact', 'fields' => [
                    $this->field('emergency_name', 'Emergency Contact Name', 'text', ['required' => true]),
                    $this->field('emergency_phone', 'Emergency Contact Phone', 'tel', ['required' => true]),
                    $this->field('emergency_relationship', 'Relationship', 'text'),
                ]],
                ['title' => 'Insurance', 'fields' => [
                    $this->field('insurance_provider', 'Insurance Provider', 'text'),
                    $this->field('insurance_policy_number', 'Policy Number', 'text'),
                    $this->field('insurance_expiry', 'Policy Expiry', 'date'),
                ]],
            ],
        ];

        return [
            $this->base('REG-001', 'New Patient Registration', 'registration', $schema, [
                'description' => 'Standard new patient registration form with demographics, contact, emergency, and insurance information.',
                'module' => 'patient',
                'workflow' => 'intake',
                'document_number_prefix' => 'REG',
                'linked_to_encounter' => false,
            ]),
            $this->base('REG-002', 'Patient Intake Form', 'registration', $schema, [
                'description' => 'Quick patient intake for walk-in and emergency registration.',
                'module' => 'patient',
                'workflow' => 'intake',
                'document_number_prefix' => 'INT',
            ]),
            $this->base('REG-003', 'Patient Information Update', 'registration', [
                'sections' => [
                    ['title' => 'Updated Information', 'fields' => [
                        $this->field('field_to_update', 'Field to Update', 'select', ['options' => ['Phone', 'Address', 'Insurance', 'Emergency Contact', 'Other']]),
                        $this->field('new_value', 'New Value', 'textarea', ['required' => true]),
                        $this->field('reason', 'Reason for Update', 'textarea'),
                    ]],
                ],
            ], [
                'description' => 'Form for patients to request updates to their registration information.',
                'module' => 'patient',
                'workflow' => 'documentation',
                'generates_document_number' => false,
            ]),
            $this->base('REG-004', 'OPD Patient Intake', 'registration', $schema, [
                'description' => 'Outpatient department specific intake form.',
                'module' => 'patient',
                'department' => 'OPD',
                'workflow' => 'intake',
                'document_number_prefix' => 'OPD',
            ]),
            $this->base('REG-005', 'IPD Patient Intake', 'registration', $schema, [
                'description' => 'Inpatient department admission intake form.',
                'module' => 'ipd',
                'department' => 'IPD',
                'workflow' => 'intake',
                'document_number_prefix' => 'IPD',
            ]),
            $this->base('REG-006', 'Emergency Patient Intake', 'registration', $schema, [
                'description' => 'Emergency department minimal-data intake for rapid registration.',
                'module' => 'emergency',
                'department' => 'Emergency',
                'workflow' => 'intake',
                'document_number_prefix' => 'EMR',
            ]),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  CLINICAL FORMS
    // ════════════════════════════════════════════════════════════════

    private function clinicalForms(): array
    {
        return [
            $this->base('CLN-001', 'Medical Intake', 'clinical', [
                'sections' => [
                    ['title' => 'Chief Complaint', 'fields' => [
                        $this->field('chief_complaint', 'Chief Complaint', 'textarea', ['required' => true]),
                        $this->field('onset_date', 'Onset Date', 'date'),
                        $this->field('duration', 'Duration', 'text'),
                        $this->field('severity', 'Severity (1-10)', 'number', ['min' => 1, 'max' => 10]),
                    ]],
                    ['title' => 'Medical History', 'fields' => [
                        $this->field('past_illnesses', 'Past Illnesses', 'textarea'),
                        $this->field('current_medications', 'Current Medications', 'textarea'),
                        $this->field('allergies', 'Known Allergies', 'textarea'),
                        $this->field('surgical_history', 'Surgical History', 'textarea'),
                        $this->field('family_history', 'Family History', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'assessment', 'document_number_prefix' => 'MED']),

            $this->base('CLN-002', 'Patient Assessment', 'clinical', [
                'sections' => [
                    ['title' => 'Assessment', 'fields' => [
                        $this->field('subjective', 'Subjective', 'textarea', ['required' => true]),
                        $this->field('objective', 'Objective', 'textarea'),
                        $this->field('assessment', 'Assessment', 'textarea', ['required' => true]),
                        $this->field('plan', 'Plan', 'textarea', ['required' => true]),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'assessment', 'linked_to_encounter' => true]),

            $this->base('CLN-003', 'Doctor Consultation', 'clinical', [
                'sections' => [
                    ['title' => 'Consultation Notes', 'fields' => [
                        $this->field('history_present_illness', 'History of Present Illness', 'textarea', ['required' => true]),
                        $this->field('examination_findings', 'Examination Findings', 'textarea'),
                        $this->field('diagnosis', 'Diagnosis', 'textarea', ['required' => true]),
                        $this->field('treatment_plan', 'Treatment Plan', 'textarea', ['required' => true]),
                        $this->field('follow_up', 'Follow-up Instructions', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'consultation', 'linked_to_encounter' => true, 'allowed_roles' => ['doctor']]),

            $this->base('CLN-004', 'Progress Note', 'clinical', [
                'sections' => [
                    ['title' => 'Progress', 'fields' => [
                        $this->field('subjective', 'Subjective', 'textarea'),
                        $this->field('objective', 'Objective', 'textarea'),
                        $this->field('assessment', 'Assessment', 'textarea'),
                        $this->field('plan', 'Plan', 'textarea'),
                        $this->field('note_type', 'Note Type', 'select', ['options' => ['Initial', 'Progress', 'Consultation', 'Discharge']]),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'documentation', 'linked_to_encounter' => true]),

            $this->base('CLN-005', 'Discharge Summary', 'clinical', [
                'sections' => [
                    ['title' => 'Discharge Information', 'fields' => [
                        $this->field('admission_date', 'Admission Date', 'date', ['required' => true]),
                        $this->field('discharge_date', 'Discharge Date', 'date', ['required' => true]),
                        $this->field('diagnosis', 'Discharge Diagnosis', 'textarea', ['required' => true]),
                        $this->field('treatment_summary', 'Treatment Summary', 'textarea'),
                        $this->field('discharge_medications', 'Discharge Medications', 'textarea'),
                        $this->field('follow_up_instructions', 'Follow-up Instructions', 'textarea'),
                        $this->field('dietary_instructions', 'Dietary Instructions', 'textarea'),
                        $this->field('activity_restrictions', 'Activity Restrictions', 'textarea'),
                    ]],
                ],
            ], ['module' => 'ipd', 'workflow' => 'discharge', 'linked_to_admission' => true, 'document_number_prefix' => 'DSC']),

            $this->base('CLN-006', 'Treatment Plan', 'clinical', [
                'sections' => [
                    ['title' => 'Treatment Plan', 'fields' => [
                        $this->field('diagnosis', 'Diagnosis', 'textarea', ['required' => true]),
                        $this->field('goals', 'Treatment Goals', 'textarea', ['required' => true]),
                        $this->field('interventions', 'Planned Interventions', 'textarea'),
                        $this->field('medications', 'Medications', 'textarea'),
                        $this->field('follow_up', 'Follow-up Plan', 'textarea'),
                        $this->field('patient_education', 'Patient Education', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'assessment', 'linked_to_encounter' => true]),

            $this->base('CLN-007', 'Allergy History', 'clinical', [
                'sections' => [
                    ['title' => 'Allergies', 'fields' => [
                        $this->field('allergen', 'Allergen', 'text', ['required' => true]),
                        $this->field('reaction', 'Reaction', 'text', ['required' => true]),
                        $this->field('severity', 'Severity', 'select', ['options' => ['Mild', 'Moderate', 'Severe', 'Life-threatening']]),
                        $this->field('onset_date', 'Onset Date', 'date'),
                        $this->field('status', 'Status', 'select', ['options' => ['Active', 'Inactive', 'Resolved']]),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'documentation']),

            $this->base('CLN-008', 'Medication History', 'clinical', [
                'sections' => [
                    ['title' => 'Medications', 'fields' => [
                        $this->field('medication_name', 'Medication Name', 'text', ['required' => true]),
                        $this->field('dosage', 'Dosage', 'text'),
                        $this->field('frequency', 'Frequency', 'text'),
                        $this->field('route', 'Route', 'select', ['options' => ['Oral', 'IV', 'IM', 'SC', 'Topical', 'Inhaled']]),
                        $this->field('start_date', 'Start Date', 'date'),
                        $this->field('prescribing_doctor', 'Prescribing Doctor', 'text'),
                        $this->field('reason', 'Reason', 'textarea'),
                        $this->field('status', 'Status', 'select', ['options' => ['Current', 'Discontinued', 'On Hold']]),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'documentation']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  CONSENT FORMS
    // ════════════════════════════════════════════════════════════════

    private function consentForms(): array
    {
        $consentSchema = function (string $title, string $description) {
            return [
                'sections' => [
                    ['title' => 'Consent Details', 'fields' => [
                        $this->field('consent_text', 'Consent Statement', 'textarea', ['required' => true, 'default' => $description]),
                        $this->field('patient_signature', 'Patient Signature', 'signature', ['required' => true]),
                        $this->field('patient_name', 'Patient Name', 'text', ['required' => true]),
                        $this->field('patient_date', 'Date', 'date', ['required' => true]),
                        $this->field('guardian_signature', 'Guardian Signature', 'signature'),
                        $this->field('guardian_name', 'Guardian Name', 'text'),
                        $this->field('witness_signature', 'Witness Signature', 'signature'),
                        $this->field('witness_name', 'Witness Name', 'text'),
                        $this->field('clinician_signature', 'Clinician Signature', 'signature', ['required' => true]),
                        $this->field('clinician_name', 'Clinician Name', 'text', ['required' => true]),
                    ]],
                ],
            ];
        };

        return [
            $this->base('CON-001', 'General Treatment Consent', 'consent',
                $consentSchema('General Treatment Consent', 'I consent to receive medical treatment and care as deemed necessary by the attending physician.'),
                ['module' => 'emr', 'workflow' => 'consent', 'document_number_prefix' => 'CON']),

            $this->base('CON-002', 'Surgery Consent', 'consent',
                $consentSchema('Surgery Consent', 'I consent to undergo the proposed surgical procedure as explained by the surgeon.'),
                ['module' => 'emr', 'workflow' => 'consent', 'specialty' => 'surgery']),

            $this->base('CON-003', 'Procedure Consent', 'consent',
                $consentSchema('Procedure Consent', 'I consent to the medical procedure as described by the healthcare provider.'),
                ['module' => 'emr', 'workflow' => 'consent']),

            $this->base('CON-004', 'Medication Consent', 'consent',
                $consentSchema('Medication Consent', 'I consent to the administration of medication as prescribed by the attending physician.'),
                ['module' => 'pharmacy', 'workflow' => 'consent']),

            $this->base('CON-005', 'Imaging Consent', 'consent',
                $consentSchema('Imaging Consent', 'I consent to undergo diagnostic imaging as ordered by the healthcare provider.'),
                ['module' => 'radiology', 'workflow' => 'consent']),

            $this->base('CON-006', 'Hospital Admission Consent', 'consent',
                $consentSchema('Hospital Admission Consent', 'I consent to be admitted to the hospital for inpatient care and treatment.'),
                ['module' => 'ipd', 'workflow' => 'consent', 'document_number_prefix' => 'ADM']),

            $this->base('CON-007', 'Discharge Acknowledgement', 'consent',
                $consentSchema('Discharge Acknowledgement', 'I acknowledge that I have been informed about my discharge instructions, medications, and follow-up care.'),
                ['module' => 'ipd', 'workflow' => 'discharge']),

            $this->base('CON-008', 'Telemedicine Consent', 'consent',
                $consentSchema('Telemedicine Consent', 'I consent to receive healthcare services via telemedicine/teleconsultation.'),
                ['module' => 'telehealth', 'workflow' => 'consent']),

            $this->base('CON-009', 'Privacy Acknowledgement', 'consent',
                $consentSchema('Privacy Acknowledgement', 'I acknowledge that I have been informed about the hospital\'s privacy policy regarding my health information.'),
                ['module' => 'admin', 'workflow' => 'consent']),

            $this->base('CON-010', 'Diagnostic Consent', 'consent',
                $consentSchema('Diagnostic Consent', 'I consent to undergo diagnostic tests and procedures as ordered by the healthcare provider.'),
                ['module' => 'laboratory', 'workflow' => 'consent']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  SPECIALTY FORMS
    // ════════════════════════════════════════════════════════════════

    private function specialtyForms(): array
    {
        $specialtyAssessment = function (string $specialty, string $title) {
            return $this->base("SPE-{$specialty}", "{$title} Assessment", 'specialty', [
                'sections' => [
                    ['title' => 'Clinical Assessment', 'fields' => [
                        $this->field('history', 'History', 'textarea', ['required' => true]),
                        $this->field('examination', 'Examination Findings', 'textarea'),
                        $this->field('investigations', 'Investigations', 'textarea'),
                        $this->field('diagnosis', 'Diagnosis', 'textarea', ['required' => true]),
                        $this->field('treatment_plan', 'Treatment Plan', 'textarea'),
                        $this->field('follow_up', 'Follow-up', 'textarea'),
                    ]],
                ],
            ], [
                'module' => 'emr',
                'specialty' => $specialty,
                'workflow' => 'assessment',
                'linked_to_encounter' => true,
                'allowed_roles' => ['doctor'],
            ]);
        };

        return [
            $specialtyAssessment('pediatrics', 'Pediatrics'),
            $specialtyAssessment('orthopedics', 'Orthopedics'),
            $specialtyAssessment('cardiology', 'Cardiology'),
            $specialtyAssessment('neurology', 'Neurology'),
            $specialtyAssessment('dermatology', 'Dermatology'),
            $specialtyAssessment('gastroenterology', 'Gastroenterology'),
            $specialtyAssessment('nephrology', 'Nephrology'),
            $specialtyAssessment('endocrinology', 'Endocrinology'),
            $specialtyAssessment('gynecology', 'Gynecology/Obstetrics'),
            $specialtyAssessment('ent', 'ENT'),
            $specialtyAssessment('ophthalmology', 'Ophthalmology'),
            $specialtyAssessment('urology', 'Urology'),
            $specialtyAssessment('general_surgery', 'General Surgery'),
            $specialtyAssessment('oncology', 'Oncology'),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  PEDIATRIC FORMS
    // ════════════════════════════════════════════════════════════════

    private function pediatricForms(): array
    {
        return [
            $this->base('PED-001', 'Pediatric Intake', 'pediatric', [
                'sections' => [
                    ['title' => 'Child Information', 'fields' => [
                        $this->field('child_name', 'Child Name', 'text', ['required' => true]),
                        $this->field('date_of_birth', 'Date of Birth', 'date', ['required' => true]),
                        $this->field('sex', 'Sex', 'select', ['options' => ['Male', 'Female'], 'required' => true]),
                        $this->field('weight_kg', 'Weight (kg)', 'number'),
                        $this->field('height_cm', 'Height (cm)', 'number'),
                    ]],
                    ['title' => 'Guardian Information', 'fields' => [
                        $this->field('guardian_name', 'Guardian Name', 'text', ['required' => true]),
                        $this->field('guardian_relationship', 'Relationship', 'select', ['options' => ['Mother', 'Father', 'Grandparent', 'Guardian', 'Other']]),
                        $this->field('guardian_phone', 'Guardian Phone', 'tel', ['required' => true]),
                    ]],
                    ['title' => 'Immunization History', 'fields' => [
                        $this->field('immunizations', 'Immunizations Received', 'textarea'),
                        $this->field('last_immunization_date', 'Last Immunization Date', 'date'),
                    ]],
                ],
            ], ['module' => 'emr', 'specialty' => 'pediatrics', 'workflow' => 'intake']),

            $this->base('PED-002', 'Growth Measurement', 'pediatric', [
                'sections' => [
                    ['title' => 'Measurements', 'fields' => [
                        $this->field('weight_kg', 'Weight (kg)', 'number', ['required' => true]),
                        $this->field('height_cm', 'Height (cm)', 'number', ['required' => true]),
                        $this->field('head_circumference_cm', 'Head Circumference (cm)', 'number'),
                        $this->field('bmi', 'BMI', 'number'),
                        $this->field('measurement_date', 'Measurement Date', 'date', ['required' => true]),
                        $this->field('notes', 'Notes', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'specialty' => 'pediatrics', 'workflow' => 'assessment']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  MENTAL HEALTH FORMS
    // ════════════════════════════════════════════════════════════════

    private function mentalHealthForms(): array
    {
        return [
            $this->base('MH-001', 'Mental Health Intake', 'mental_health', [
                'sections' => [
                    ['title' => 'Presenting Concerns', 'fields' => [
                        $this->field('chief_complaint', 'Chief Complaint', 'textarea', ['required' => true]),
                        $this->field('duration', 'Duration of Symptoms', 'text'),
                        $this->field('impact_on_daily_life', 'Impact on Daily Life', 'textarea'),
                    ]],
                    ['title' => 'Mental Health History', 'fields' => [
                        $this->field('previous_treatment', 'Previous Mental Health Treatment', 'textarea'),
                        $this->field('current_medications', 'Current Psychiatric Medications', 'textarea'),
                        $this->field('substance_use', 'Substance Use History', 'textarea'),
                        $this->field('family_psychiatric_history', 'Family Psychiatric History', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'specialty' => 'psychiatry', 'workflow' => 'intake']),

            $this->base('MH-002', 'Psychiatric Assessment', 'mental_health', [
                'sections' => [
                    ['title' => 'Assessment', 'fields' => [
                        $this->field('mental_status_exam', 'Mental Status Examination', 'textarea', ['required' => true]),
                        $this->field('risk_assessment', 'Risk Assessment', 'textarea'),
                        $this->field('diagnosis', 'Diagnosis', 'textarea', ['required' => true]),
                        $this->field('treatment_recommendations', 'Treatment Recommendations', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'specialty' => 'psychiatry', 'workflow' => 'assessment', 'allowed_roles' => ['doctor']]),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  NUTRITION FORMS
    // ════════════════════════════════════════════════════════════════

    private function nutritionForms(): array
    {
        return [
            $this->base('NUT-001', 'Nutrition Consultation', 'nutrition', [
                'sections' => [
                    ['title' => 'Dietary Assessment', 'fields' => [
                        $this->field('dietary_history', 'Dietary History', 'textarea', ['required' => true]),
                        $this->field('food_allergies', 'Food Allergies', 'textarea'),
                        $this->field('dietary_restrictions', 'Dietary Restrictions', 'textarea'),
                        $this->field('current_diet', 'Current Diet', 'textarea'),
                    ]],
                    ['title' => 'Body Measurements', 'fields' => [
                        $this->field('height_cm', 'Height (cm)', 'number'),
                        $this->field('weight_kg', 'Weight (kg)', 'number'),
                        $this->field('bmi', 'BMI', 'number'),
                        $this->field('waist_circumference', 'Waist Circumference (cm)', 'number'),
                    ]],
                    ['title' => 'Nutrition Goals', 'fields' => [
                        $this->field('goals', 'Nutrition Goals', 'textarea'),
                        $this->field('recommendations', 'Recommendations', 'textarea'),
                        $this->field('follow_up_date', 'Follow-up Date', 'date'),
                    ]],
                ],
            ], ['module' => 'emr', 'specialty' => 'nutrition', 'workflow' => 'assessment']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  DENTAL FORMS
    // ════════════════════════════════════════════════════════════════

    private function dentalForms(): array
    {
        return [
            $this->base('DEN-001', 'Dental Consultation', 'dental', [
                'sections' => [
                    ['title' => 'Dental Information', 'fields' => [
                        $this->field('chief_complaint', 'Chief Complaint', 'textarea', ['required' => true]),
                        $this->field('dental_history', 'Dental History', 'textarea'),
                        $this->field('oral_examination', 'Oral Examination Findings', 'textarea'),
                        $this->field('diagnosis', 'Diagnosis', 'textarea'),
                        $this->field('treatment_plan', 'Treatment Plan', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'specialty' => 'dental', 'workflow' => 'consultation']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  IMAGING / RADIOLOGY FORMS
    // ════════════════════════════════════════════════════════════════

    private function imagingForms(): array
    {
        return [
            $this->base('IMG-001', 'Radiology Request', 'imaging', [
                'sections' => [
                    ['title' => 'Request Details', 'fields' => [
                        $this->field('examination_type', 'Examination Type', 'select', ['options' => ['X-Ray', 'CT Scan', 'MRI', 'Ultrasound', 'Mammography', 'Fluoroscopy'], 'required' => true]),
                        $this->field('body_part', 'Body Part/Region', 'text', ['required' => true]),
                        $this->field('clinical_indication', 'Clinical Indication', 'textarea', ['required' => true]),
                        $this->field('contrast', 'Contrast Required', 'select', ['options' => ['No', 'Yes', 'Already Allergic']]),
                        $this->field('pregnancy_status', 'Pregnancy Status', 'select', ['options' => ['Not Applicable', 'Not Pregnant', 'Possibly Pregnant', 'Pregnant']]),
                        $this->field('previous_imaging', 'Previous Imaging', 'textarea'),
                    ]],
                ],
            ], ['module' => 'radiology', 'workflow' => 'order', 'document_number_prefix' => 'RD']),

            $this->base('IMG-002', 'MRI Screening Form', 'imaging', [
                'sections' => [
                    ['title' => 'MRI Safety Screening', 'fields' => [
                        $this->field('metal_implants', 'Metal Implants', 'select', ['options' => ['None', 'Pacemaker', 'Joint Replacement', 'Other'], 'required' => true]),
                        $this->field('surgical_history', 'Surgical History (relevant)', 'textarea'),
                        $this->field('claustrophobia', 'Claustrophobia', 'select', ['options' => ['No', 'Mild', 'Severe']]),
                        $this->field('pregnancy_status', 'Pregnancy Status', 'select', ['options' => ['Not Applicable', 'Not Pregnant', 'Possibly Pregnant', 'Pregnant']]),
                        $this->field('contrast_allergy', 'Contrast Allergy', 'select', ['options' => ['No', 'Yes']]),
                    ]],
                ],
            ], ['module' => 'radiology', 'workflow' => 'screening']),

            $this->base('IMG-003', 'Radiology Report', 'imaging', [
                'sections' => [
                    ['title' => 'Report', 'fields' => [
                        $this->field('findings', 'Findings', 'textarea', ['required' => true]),
                        $this->field('impression', 'Impression', 'textarea', ['required' => true]),
                        $this->field('recommendations', 'Recommendations', 'textarea'),
                        $this->field('critical_finding', 'Critical Finding', 'select', ['options' => ['No', 'Yes']]),
                    ]],
                ],
            ], ['module' => 'radiology', 'workflow' => 'report', 'allowed_roles' => ['doctor']]),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  LABORATORY FORMS
    // ════════════════════════════════════════════════════════════════

    private function laboratoryForms(): array
    {
        return [
            $this->base('LAB-001', 'Investigation Order', 'laboratory', [
                'sections' => [
                    ['title' => 'Order Details', 'fields' => [
                        $this->field('test_name', 'Test Name', 'text', ['required' => true]),
                        $this->field('specimen_type', 'Specimen Type', 'select', ['options' => ['Blood', 'Urine', 'Stool', 'CSF', 'Sputum', 'Swab', 'Other']]),
                        $this->field('priority', 'Priority', 'select', ['options' => ['Routine', 'Urgent', 'STAT']]),
                        $this->field('clinical_history', 'Clinical History', 'textarea'),
                        $this->field('special_instructions', 'Special Instructions', 'textarea'),
                    ]],
                ],
            ], ['module' => 'laboratory', 'workflow' => 'order', 'document_number_prefix' => 'LO']),

            $this->base('LAB-002', 'Sample Collection', 'laboratory', [
                'sections' => [
                    ['title' => 'Collection Details', 'fields' => [
                        $this->field('sample_number', 'Sample Number', 'text', ['required' => true]),
                        $this->field('specimen_type', 'Specimen Type', 'text', ['required' => true]),
                        $this->field('collection_date', 'Collection Date/Time', 'datetime', ['required' => true]),
                        $this->field('collected_by', 'Collected By', 'text'),
                        $this->field('collection_method', 'Collection Method', 'text'),
                        $this->field('transport_condition', 'Transport Condition', 'text'),
                    ]],
                ],
            ], ['module' => 'laboratory', 'workflow' => 'documentation']),

            $this->base('LAB-003', 'Laboratory Result', 'laboratory', [
                'sections' => [
                    ['title' => 'Result Details', 'fields' => [
                        $this->field('test_name', 'Test Name', 'text', ['required' => true]),
                        $this->field('result_value', 'Result Value', 'text', ['required' => true]),
                        $this->field('reference_range', 'Reference Range', 'text'),
                        $this->field('unit', 'Unit', 'text'),
                        $this->field('flag', 'Flag', 'select', ['options' => ['Normal', 'High', 'Low', 'Critical']]),
                        $this->field('notes', 'Notes', 'textarea'),
                    ]],
                ],
            ], ['module' => 'laboratory', 'workflow' => 'result']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  ADMISSION / IPD FORMS
    // ════════════════════════════════════════════════════════════════

    private function admissionForms(): array
    {
        return [
            $this->base('ADM-001', 'Hospital Admission Form', 'admission', [
                'sections' => [
                    ['title' => 'Admission Details', 'fields' => [
                        $this->field('admission_date', 'Admission Date/Time', 'datetime', ['required' => true]),
                        $this->field('admission_type', 'Admission Type', 'select', ['options' => ['Elective', 'Emergency', 'Transfer', 'Maternity'], 'required' => true]),
                        $this->field('department', 'Department', 'text', ['required' => true]),
                        $this->field('ward', 'Ward', 'text'),
                        $this->field('bed_number', 'Bed Number', 'text'),
                        $this->field('attending_doctor', 'Attending Doctor', 'text', ['required' => true]),
                    ]],
                    ['title' => 'Clinical Information', 'fields' => [
                        $this->field('chief_complaint', 'Chief Complaint', 'textarea', ['required' => true]),
                        $this->field('diagnosis', 'Admission Diagnosis', 'textarea', ['required' => true]),
                        $this->field('relevant_history', 'Relevant Medical History', 'textarea'),
                        $this->field('allergies', 'Known Allergies', 'textarea'),
                    ]],
                ],
            ], ['module' => 'ipd', 'workflow' => 'intake', 'document_number_prefix' => 'ADM', 'linked_to_admission' => true]),

            $this->base('ADM-002', 'Transfer Form', 'admission', [
                'sections' => [
                    ['title' => 'Transfer Details', 'fields' => [
                        $this->field('from_ward', 'From Ward', 'text', ['required' => true]),
                        $this->field('from_bed', 'From Bed', 'text'),
                        $this->field('to_ward', 'To Ward', 'text', ['required' => true]),
                        $this->field('to_bed', 'To Bed', 'text'),
                        $this->field('reason', 'Reason for Transfer', 'textarea', ['required' => true]),
                        $this->field('transfer_date', 'Transfer Date/Time', 'datetime', ['required' => true]),
                        $this->field('condition_at_transfer', 'Patient Condition at Transfer', 'textarea'),
                    ]],
                ],
            ], ['module' => 'ipd', 'workflow' => 'documentation', 'linked_to_admission' => true]),

            $this->base('ADM-003', 'Discharge Instructions', 'admission', [
                'sections' => [
                    ['title' => 'Discharge Instructions', 'fields' => [
                        $this->field('diagnosis', 'Final Diagnosis', 'textarea', ['required' => true]),
                        $this->field('discharge_medications', 'Discharge Medications', 'textarea', ['required' => true]),
                        $this->field('follow_up_date', 'Follow-up Date', 'date'),
                        $this->field('dietary_instructions', 'Dietary Instructions', 'textarea'),
                        $this->field('activity_restrictions', 'Activity Restrictions', 'textarea'),
                        $this->field('warning_signs', 'Warning Signs to Watch For', 'textarea'),
                        $this->field('patient_acknowledgement', 'Patient Acknowledgement', 'signature'),
                    ]],
                ],
            ], ['module' => 'ipd', 'workflow' => 'discharge', 'linked_to_admission' => true]),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  ICU FORMS
    // ════════════════════════════════════════════════════════════════

    private function icuForms(): array
    {
        return [
            $this->base('ICU-001', 'ICU Assessment', 'icu', [
                'sections' => [
                    ['title' => 'ICU Assessment', 'fields' => [
                        $this->field('admission_diagnosis', 'Admission Diagnosis', 'textarea', ['required' => true]),
                        $this->field('severity_score', 'Severity Score (APACHE/SOFA)', 'text'),
                        $this->field('vital_signs', 'Vital Signs', 'textarea'),
                        $this->field('ventilation_status', 'Ventilation Status', 'select', ['options' => ['None', 'Supplemental O2', 'NIV', 'Mechanical Ventilation']]),
                        $this->field('sedation_level', 'Sedation Level (RASS)', 'text'),
                        $this->field('goals_of_care', 'Goals of Care', 'textarea'),
                    ]],
                ],
            ], ['module' => 'ipd', 'specialty' => 'icu', 'workflow' => 'assessment']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  PHARMACY FORMS
    // ════════════════════════════════════════════════════════════════

    private function pharmacyForms(): array
    {
        return [
            $this->base('RX-001', 'Medication Order', 'pharmacy', [
                'sections' => [
                    ['title' => 'Prescription', 'fields' => [
                        $this->field('medication', 'Medication', 'text', ['required' => true]),
                        $this->field('strength', 'Strength', 'text'),
                        $this->field('dosage', 'Dosage', 'text', ['required' => true]),
                        $this->field('route', 'Route', 'select', ['options' => ['Oral', 'IV', 'IM', 'SC', 'Topical', 'Inhaled', 'Rectal']]),
                        $this->field('frequency', 'Frequency', 'text', ['required' => true]),
                        $this->field('duration', 'Duration', 'text'),
                        $this->field('quantity', 'Quantity', 'number'),
                        $this->field('instructions', 'Special Instructions', 'textarea'),
                        $this->field('refills', 'Refills', 'number'),
                    ]],
                ],
            ], ['module' => 'pharmacy', 'workflow' => 'order', 'document_number_prefix' => 'RX']),

            $this->base('RX-002', 'Dispensing Record', 'pharmacy', [
                'sections' => [
                    ['title' => 'Dispensing', 'fields' => [
                        $this->field('medication', 'Medication Dispensed', 'text', ['required' => true]),
                        $this->field('batch_number', 'Batch Number', 'text'),
                        $this->field('quantity_dispensed', 'Quantity Dispensed', 'number', ['required' => true]),
                        $this->field('expiry_date', 'Expiry Date', 'date'),
                        $this->field('dispensed_by', 'Dispensed By', 'text'),
                        $this->field('dispensing_date', 'Dispensing Date', 'datetime'),
                        $this->field('patient_counselled', 'Patient Counselled', 'select', ['options' => ['Yes', 'No']]),
                    ]],
                ],
            ], ['module' => 'pharmacy', 'workflow' => 'documentation']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  REFERRAL FORMS
    // ════════════════════════════════════════════════════════════════

    private function referralForms(): array
    {
        return [
            $this->base('REF-001', 'Patient Referral', 'referral', [
                'sections' => [
                    ['title' => 'Referral Details', 'fields' => [
                        $this->field('referring_provider', 'Referring Provider', 'text', ['required' => true]),
                        $this->field('receiving_provider', 'Receiving Provider', 'text'),
                        $this->field('receiving_facility', 'Receiving Facility', 'text'),
                        $this->field('department', 'Department', 'text'),
                        $this->field('reason', 'Reason for Referral', 'textarea', ['required' => true]),
                        $this->field('urgency', 'Urgency', 'select', ['options' => ['Routine', 'Urgent', 'Emergency'], 'required' => true]),
                        $this->field('clinical_summary', 'Clinical Summary', 'textarea'),
                        $this->field('relevant_investigations', 'Relevant Investigations', 'textarea'),
                    ]],
                ],
            ], ['module' => 'emr', 'workflow' => 'referral', 'document_number_prefix' => 'REF']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  INSURANCE FORMS
    // ════════════════════════════════════════════════════════════════

    private function insuranceForms(): array
    {
        return [
            $this->base('INS-001', 'Insurance Verification', 'insurance', [
                'sections' => [
                    ['title' => 'Insurance Information', 'fields' => [
                        $this->field('provider', 'Insurance Provider', 'text', ['required' => true]),
                        $this->field('policy_number', 'Policy Number', 'text', ['required' => true]),
                        $this->field('group_number', 'Group Number', 'text'),
                        $this->field('member_name', 'Member Name', 'text', ['required' => true]),
                        $this->field('relationship', 'Relationship to Patient', 'select', ['options' => ['Self', 'Spouse', 'Child', 'Other']]),
                        $this->field('coverage_start', 'Coverage Start Date', 'date'),
                        $this->field('coverage_end', 'Coverage End Date', 'date'),
                        $this->field('verification_status', 'Verification Status', 'select', ['options' => ['Verified', 'Pending', 'Rejected']]),
                    ]],
                ],
            ], ['module' => 'billing', 'workflow' => 'documentation']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  TELEMEDICINE FORMS
    // ════════════════════════════════════════════════════════════════

    private function telemedicineForms(): array
    {
        return [
            $this->base('TEL-001', 'Teleconsultation Record', 'telemedicine', [
                'sections' => [
                    ['title' => 'Session Details', 'fields' => [
                        $this->field('session_date', 'Session Date/Time', 'datetime', ['required' => true]),
                        $this->field('session_type', 'Session Type', 'select', ['options' => ['Video', 'Audio', 'Chat']]),
                        $this->field('chief_complaint', 'Chief Complaint', 'textarea', ['required' => true]),
                        $this->field('clinical_notes', 'Clinical Notes', 'textarea'),
                        $this->field('prescription', 'Prescription', 'textarea'),
                        $this->field('follow_up', 'Follow-up Plan', 'textarea'),
                        $this->field('technology_consent', 'Technology Consent Confirmed', 'select', ['options' => ['Yes', 'No']]),
                    ]],
                ],
            ], ['module' => 'telehealth', 'workflow' => 'documentation']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  VITALS FORMS
    // ════════════════════════════════════════════════════════════════

    private function vitalsForms(): array
    {
        return [
            $this->base('VIT-001', 'Vital Signs Recording', 'clinical', [
                'sections' => [
                    ['title' => 'Vital Signs', 'fields' => [
                        $this->field('temperature', 'Temperature (°C)', 'number', ['step' => 0.1]),
                        $this->field('pulse', 'Pulse (bpm)', 'number'),
                        $this->field('respiratory_rate', 'Respiratory Rate (/min)', 'number'),
                        $this->field('systolic_bp', 'Systolic BP (mmHg)', 'number'),
                        $this->field('diastolic_bp', 'Diastolic BP (mmHg)', 'number'),
                        $this->field('spo2', 'SpO₂ (%)', 'number'),
                        $this->field('height', 'Height (cm)', 'number'),
                        $this->field('weight', 'Weight (kg)', 'number'),
                        $this->field('pain_score', 'Pain Score (0-10)', 'number', ['min' => 0, 'max' => 10]),
                        $this->field('notes', 'Notes', 'textarea'),
                    ]],
                ],
            ], ['module' => 'nursing', 'workflow' => 'assessment', 'linked_to_encounter' => true, 'document_number_prefix' => 'VIT']),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    //  NURSING FORMS
    // ════════════════════════════════════════════════════════════════

    private function nursingForms(): array
    {
        return [
            $this->base('NUR-001', 'Nursing Assessment', 'nursing', [
                'sections' => [
                    ['title' => 'Nursing Assessment', 'fields' => [
                        $this->field('skin_assessment', 'Skin Assessment', 'textarea'),
                        $this->field('respiratory_assessment', 'Respiratory Assessment', 'textarea'),
                        $this->field('cardiovascular_assessment', 'Cardiovascular Assessment', 'textarea'),
                        $this->field('neurological_assessment', 'Neurological Assessment', 'textarea'),
                        $this->field('mobility', 'Mobility Status', 'select', ['options' => ['Independent', 'Assisted', 'Wheelchair', 'Bedbound']]),
                        $this->field('fall_risk', 'Fall Risk Assessment', 'select', ['options' => ['Low', 'Moderate', 'High']]),
                        $this->field('pressure_risk', 'Pressure Injury Risk', 'select', ['options' => ['Low', 'Moderate', 'High']]),
                        $this->field('pain_assessment', 'Pain Assessment', 'textarea'),
                        $this->field('nutrition_screen', 'Nutrition Screening', 'textarea'),
                        $this->field('psychosocial', 'Psychosocial Assessment', 'textarea'),
                    ]],
                ],
            ], ['module' => 'nursing', 'workflow' => 'assessment', 'linked_to_encounter' => true, 'allowed_roles' => ['nurse']]),
        ];
    }
}
