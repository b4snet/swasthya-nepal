<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PharmacyPrescription;
use App\Models\RadiologyStudy;
use App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Returns pre-filled data for the document generation wizard.
 *
 * Given a documentType + patientId + optional encounterId, this endpoint
 * queries the relevant clinical tables and returns structured data the
 * frontend can populate into the wizard form.
 */
final class DocumentPrefillController extends Controller
{
    /**
     * GET /documents/prefill?documentType=discharge_summary&patientId=...&encounterId=...
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'documentType' => ['required', 'string'],
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
        ]);

        $patient = Patient::findOrFail($validated['patientId']);
        $encounter = ! empty($validated['encounterId'])
            ? Encounter::with(['provider:id,full_name', 'service:id,name'])->find($validated['encounterId'])
            : null;

        $patientData = [
            'id' => $patient->getKey(),
            'fullName' => $patient->full_name,
            'mrn' => $patient->mrn,
            'dateOfBirth' => $patient->date_of_birth?->toDateString(),
            'sex' => $patient->sex,
            'bloodGroup' => $patient->blood_group,
            'phone' => $patient->phone,
            'email' => $patient->email,
            'address' => $patient->address,
        ];

        $encounterData = $encounter ? [
            'id' => $encounter->getKey(),
            'type' => $encounter->type,
            'status' => $encounter->status,
            'providerName' => $encounter->provider?->full_name,
            'serviceName' => $encounter->service?->name,
            'chiefComplaint' => $encounter->chief_complaint,
            'startedAt' => $encounter->started_at?->toIso8601String(),
            'endedAt' => $encounter->ended_at?->toIso8601String(),
        ] : null;

        // Type-specific pre-fill data
        $typeData = match ($validated['documentType']) {
            'lab_report' => $this->prefillLabReport($patient, $encounter),
            'radiology_report' => $this->prefillRadiologyReport($patient, $encounter),
            'discharge_summary' => $this->prefillDischargeSummary($patient, $encounter),
            'prescription' => $this->prefillPrescription($patient, $encounter),
            'consent' => $this->prefillConsent($patient, $encounter),
            'referral' => $this->prefillReferral($patient, $encounter),
            'clinical_note' => $this->prefillClinicalNote($patient, $encounter),
            default => [],
        };

        return Envelope::success(data: [
            'patient' => $patientData,
            'encounter' => $encounterData,
            'prefill' => $typeData,
        ], request: $request);
    }

    private function prefillLabReport(Patient $patient, ?Encounter $encounter): array
    {
        $query = LabOrder::query()
            ->where('patient_id', $patient->getKey())
            ->with(['test:id,name,category,referenceRange'])
            ->orderByDesc('created_at');

        if ($encounter) {
            $query->where('encounter_id', $encounter->getKey());
        }

        $orders = $query->limit(20)->get();

        return [
            'title' => "Laboratory Report — {$patient->full_name}",
            'category' => 'clinical',
            'sections' => [
                [
                    'heading' => 'Patient Information',
                    'fields' => [
                        ['label' => 'Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                        ['label' => 'Sex', 'value' => $patient->sex],
                    ],
                ],
                [
                    'heading' => 'Laboratory Orders',
                    'fields' => $orders->map(fn ($order) => [
                        'label' => $order->test?->name ?? 'Unknown Test',
                        'value' => $order->status,
                    ])->toArray(),
                ],
            ],
            'availableFields' => ['clinicalIndication', 'specimenType', 'collectionDate', 'resultNotes'],
        ];
    }

    private function prefillRadiologyReport(Patient $patient, ?Encounter $encounter): array
    {
        $query = RadiologyStudy::query()
            ->where('patient_id', $patient->getKey())
            ->orderByDesc('created_at');

        if ($encounter) {
            $query->where('encounter_id', $encounter->getKey());
        }

        $studies = $query->limit(10)->get();

        return [
            'title' => "Radiology Report — {$patient->full_name}",
            'category' => 'clinical',
            'sections' => [
                [
                    'heading' => 'Patient Information',
                    'fields' => [
                        ['label' => 'Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                        ['label' => 'Sex', 'value' => $patient->sex],
                    ],
                ],
                [
                    'heading' => 'Imaging Studies',
                    'fields' => $studies->map(fn ($study) => [
                        'label' => $study->study_type ?? $study->modality?->name ?? 'Study',
                        'value' => $study->status,
                    ])->toArray(),
                ],
            ],
            'availableFields' => ['clinicalIndication', 'modality', 'bodyPart', 'findings', 'impression'],
        ];
    }

    private function prefillDischargeSummary(Patient $patient, ?Encounter $encounter): array
    {
        $prescriptions = PharmacyPrescription::query()
            ->where('patient_id', $patient->getKey())
            ->when($encounter, fn ($q) => $q->where('encounter_id', $encounter->getKey()))
            ->with(['lines.medication:id,generic_name,strength,unit'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $diagnoses = Diagnosis::query()
            ->where('patient_id', $patient->getKey())
            ->when($encounter, fn ($q) => $q->where('encounter_id', $encounter->getKey()))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $notes = ClinicalNote::query()
            ->where('patient_id', $patient->getKey())
            ->when($encounter, fn ($q) => $q->where('encounter_id', $encounter->getKey()))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return [
            'title' => "Discharge Summary — {$patient->full_name}",
            'category' => 'clinical',
            'sections' => [
                [
                    'heading' => 'Patient Information',
                    'fields' => [
                        ['label' => 'Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                        ['label' => 'Sex', 'value' => $patient->sex],
                        ['label' => 'Blood Group', 'value' => $patient->blood_group],
                    ],
                ],
                [
                    'heading' => 'Admission Details',
                    'fields' => [
                        ['label' => 'Provider', 'value' => $encounter?->provider?->full_name ?? ''],
                        ['label' => 'Service', 'value' => $encounter?->service?->name ?? ''],
                        ['label' => 'Admission Date', 'value' => $encounter?->started_at?->toDateString() ?? ''],
                        ['label' => 'Discharge Date', 'value' => $encounter?->ended_at?->toDateString() ?? ''],
                    ],
                ],
                [
                    'heading' => 'Diagnoses',
                    'fields' => $diagnoses->map(fn ($d) => [
                        'label' => $d->description,
                        'value' => $d->diagnosis_type . ($d->is_primary ? ' (Primary)' : ''),
                    ])->toArray(),
                ],
                [
                    'heading' => 'Prescriptions at Discharge',
                    'fields' => $prescriptions->flatMap(fn ($rx) => $rx->lines->map(fn ($line) => [
                        'label' => $line->medication?->generic_name ?? 'Medication',
                        'value' => "{$line->dose} {$line->route} {$line->frequency}",
                    ]))->toArray(),
                ],
                [
                    'heading' => 'Clinical Notes',
                    'fields' => $notes->map(fn ($n) => [
                        'label' => $n->note_type,
                        'value' => is_array($n->content) ? json_encode($n->content) : (string) $n->content,
                    ])->toArray(),
                ],
            ],
            'availableFields' => [
                'admissionDiagnosis', 'dischargeDiagnosis', 'conditionAtDischarge',
                'dischargeMedications', 'followUpInstructions', 'dietaryInstructions',
                'activityRestrictions', 'followUpDate', 'dischargeBy',
            ],
        ];
    }

    private function prefillPrescription(Patient $patient, ?Encounter $encounter): array
    {
        $prescriptions = PharmacyPrescription::query()
            ->where('patient_id', $patient->getKey())
            ->when($encounter, fn ($q) => $q->where('encounter_id', $encounter->getKey()))
            ->with(['lines.medication:id,generic_name,strength,unit,form,is_controlled'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return [
            'title' => "Prescription — {$patient->full_name}",
            'category' => 'clinical',
            'sections' => [
                [
                    'heading' => 'Patient Information',
                    'fields' => [
                        ['label' => 'Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                        ['label' => 'Sex', 'value' => $patient->sex],
                        ['label' => 'Weight', 'value' => ''],
                        ['label' => 'Allergies', 'value' => ''],
                    ],
                ],
                [
                    'heading' => 'Previous Prescriptions',
                    'fields' => $prescriptions->flatMap(fn ($rx) => $rx->lines->map(fn ($line) => [
                        'label' => $line->medication?->generic_name ?? 'Medication',
                        'value' => "{$line->dose} {$line->route} {$line->frequency}" . ($line->duration ? " x {$line->duration}" : ''),
                    ]))->toArray(),
                ],
            ],
            'availableFields' => [
                'medicationName', 'dose', 'route', 'frequency', 'duration',
                'quantity', 'refills', 'instructions', 'isControlled', 'pharmacyNotes',
            ],
        ];
    }

    private function prefillConsent(Patient $patient, ?Encounter $encounter): array
    {
        return [
            'title' => "Informed Consent — {$patient->full_name}",
            'category' => 'compliance',
            'sections' => [
                [
                    'heading' => 'Patient Identification',
                    'fields' => [
                        ['label' => 'Patient Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                        ['label' => 'Sex', 'value' => $patient->sex],
                        ['label' => 'Date', 'value' => now()->toDateString()],
                    ],
                ],
                [
                    'heading' => 'Provider',
                    'fields' => [
                        ['label' => 'Attending Physician', 'value' => $encounter?->provider?->full_name ?? ''],
                        ['label' => 'Department', 'value' => $encounter?->service?->name ?? ''],
                    ],
                ],
            ],
            'availableFields' => [
                'procedureName', 'procedureDescription', 'risksAndBenefits',
                'alternatives', 'anesthesiaType', 'consentStatement',
                'patientSignature', 'witnessSignature', 'dateSigned',
            ],
        ];
    }

    private function prefillReferral(Patient $patient, ?Encounter $encounter): array
    {
        return [
            'title' => "Referral Letter — {$patient->full_name}",
            'category' => 'clinical',
            'sections' => [
                [
                    'heading' => 'Patient Information',
                    'fields' => [
                        ['label' => 'Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                        ['label' => 'Sex', 'value' => $patient->sex],
                        ['label' => 'Phone', 'value' => $patient->phone],
                    ],
                ],
                [
                    'heading' => 'Referring Provider',
                    'fields' => [
                        ['label' => 'Physician', 'value' => $encounter?->provider?->full_name ?? ''],
                        ['label' => 'Department', 'value' => $encounter?->service?->name ?? ''],
                        ['label' => 'Date', 'value' => now()->toDateString()],
                    ],
                ],
            ],
            'availableFields' => [
                'receivingFacility', 'receivingDepartment', 'receivingPhysician',
                'reasonForReferral', 'clinicalSummary', 'urgency',
                'currentMedications', 'allergies', 'specialInstructions',
            ],
        ];
    }

    private function prefillClinicalNote(Patient $patient, ?Encounter $encounter): array
    {
        $notes = ClinicalNote::query()
            ->where('patient_id', $patient->getKey())
            ->when($encounter, fn ($q) => $q->where('encounter_id', $encounter->getKey()))
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return [
            'title' => "Clinical Note — {$patient->full_name}",
            'category' => 'clinical',
            'sections' => [
                [
                    'heading' => 'Patient Information',
                    'fields' => [
                        ['label' => 'Name', 'value' => $patient->full_name],
                        ['label' => 'MRN', 'value' => $patient->mrn],
                        ['label' => 'Date of Birth', 'value' => $patient->date_of_birth?->toDateString()],
                    ],
                ],
                [
                    'heading' => 'Encounter',
                    'fields' => [
                        ['label' => 'Provider', 'value' => $encounter?->provider?->full_name ?? ''],
                        ['label' => 'Type', 'value' => $encounter?->type ?? ''],
                        ['label' => 'Chief Complaint', 'value' => $encounter?->chief_complaint ?? ''],
                    ],
                ],
                [
                    'heading' => 'Previous Notes',
                    'fields' => $notes->map(fn ($n) => [
                        'label' => $n->note_type,
                        'value' => mb_substr(is_array($n->content) ? json_encode($n->content) : (string) $n->content, 0, 200),
                    ])->toArray(),
                ],
            ],
            'availableFields' => [
                'noteType', 'subjective', 'objective', 'assessment', 'plan',
                'vitals', 'physicalExamination', 'clinicalDecision',
            ],
        ];
    }
}
