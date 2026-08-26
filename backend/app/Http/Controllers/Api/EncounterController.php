<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Encounter\DischargeEncounterRequest;
use App\Http\Requests\Encounter\StoreClinicalNoteRequest;
use App\Http\Requests\Encounter\StoreDiagnosisRequest;
use App\Http\Requests\Encounter\StorePrescriptionRequest;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\ClinicalNote;
use App\Models\Diagnosis;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\Medication;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Staff;
use App\Services\BillingService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The clinical visit (DATABASE.md §3.17–3.21): start an encounter from a
 * checked-in appointment, document (notes, diagnoses, prescriptions), sign
 * it (immutable), and generate the bill (charges + invoice).
 *
 * Only the encounter's provider (via their staff profile) may sign; signed
 * encounters are immutable — no UPDATE path exists on clinical content.
 */
final class EncounterController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BillingService $billing,
    ) {}

    /**
     * POST /appointments/{appointment}/start-encounter — the doctor calls
     * the patient in; the encounter is created from the checked-in
     * appointment (one encounter per appointment, partial unique).
     */
    public function start(Request $request, Appointment $appointment): JsonResponse
    {
        AccessCheck::scoped($appointment, write: true);

        if ($appointment->status !== Appointment::STATUS_CHECKED_IN) {
            return Envelope::error(
                ErrorCodes::CONFLICT,
                'An encounter can only be started from a checked-in appointment (current status: '.$appointment->status.').',
                409,
                request: $request,
            );
        }

        $context = TenantContext::current();

        $encounter = DB::transaction(function () use ($appointment, $context): Encounter {
            $appointment->status = Appointment::STATUS_IN_CONSULTATION;
            $appointment->lock_version += 1;
            $appointment->save();

            return Encounter::query()->create([
                'tenant_id' => $appointment->tenant_id,
                'facility_id' => $appointment->facility_id,
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->getKey(),
                'provider_staff_id' => $appointment->provider_staff_id,
                'type' => Encounter::TYPE_OPD,
                'status' => Encounter::STATUS_OPEN,
                'started_at' => now(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);
        });

        $this->audit->record(
            'encounter.started',
            'encounter',
            $encounter->getKey(),
            ['patientId' => $encounter->patient_id, 'appointmentId' => $appointment->getKey(), 'providerStaffId' => $encounter->provider_staff_id],
            $request,
        );

        return Envelope::success(data: self::present($encounter), status: 201, request: $request);
    }

    public function show(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: false);

        $this->audit->record('encounter.viewed', 'encounter', $encounter->getKey(), ['patientId' => $encounter->patient_id], $request);

        return Envelope::success(data: self::present($encounter), request: $request);
    }

    public function notes(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: false);

        $notes = $encounter->notes()
            ->with('author:id,full_name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (ClinicalNote $note): array => [
                'id' => $note->getKey(),
                'noteType' => $note->note_type,
                'author' => $note->author ? ['id' => $note->author->getKey(), 'fullName' => $note->author->full_name] : null,
                'content' => $note->content,
                'status' => $note->status,
                'signedAt' => $note->signed_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $notes, request: $request);
    }

    public function storeNote(StoreClinicalNoteRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);
        $this->guardNotSigned($encounter, $request);

        $context = TenantContext::current();
        $author = $this->currentProvider($encounter, $context);

        $note = ClinicalNote::query()->create([
            'tenant_id' => $encounter->tenant_id,
            'encounter_id' => $encounter->getKey(),
            'note_type' => $request->validated('noteType', ClinicalNote::TYPE_CONSULTATION),
            'author_staff_id' => $author->getKey(),
            'content' => $request->validated('content'),
            'status' => ClinicalNote::STATUS_DRAFT,
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'note.drafted',
            'clinical_note',
            $note->getKey(),
            ['encounterId' => $encounter->getKey(), 'noteType' => $note->note_type, 'authorStaffId' => $author->getKey()],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $note->getKey(),
                'noteType' => $note->note_type,
                'author' => ['id' => $author->getKey(), 'fullName' => $author->full_name],
                'content' => $note->content,
                'status' => $note->status,
            ],
            status: 201,
            request: $request,
        );
    }

    /**
     * POST /encounters/{encounter}/notes/{note}/sign — a note is signed by
     * its author; signed notes are immutable.
     */
    public function signNote(Request $request, Encounter $encounter, ClinicalNote $note): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if ($note->encounter_id !== $encounter->getKey()) {
            return Envelope::error(ErrorCodes::NOT_FOUND, 'Note not found on this encounter.', 404, request: $request);
        }

        if ($note->status !== ClinicalNote::STATUS_DRAFT) {
            return Envelope::error(ErrorCodes::CONFLICT, 'Only a draft note can be signed.', 409, request: $request);
        }

        $context = TenantContext::current();
        $author = $this->currentProvider($encounter, $context);

        if ($note->author_staff_id !== $author->getKey()) {
            return Envelope::error(ErrorCodes::SCOPE_DENIED, 'Only the note author can sign it.', 403, request: $request);
        }

        $note->status = ClinicalNote::STATUS_SIGNED;
        $note->signed_at = now();
        $note->lock_version += 1;
        $note->save();

        $this->audit->record(
            'note.signed',
            'clinical_note',
            $note->getKey(),
            ['encounterId' => $encounter->getKey(), 'authorStaffId' => $author->getKey()],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $note->getKey(),
                'status' => $note->status,
                'signedAt' => $note->signed_at?->toIso8601String(),
            ],
            request: $request,
        );
    }

    public function storeDiagnosis(StoreDiagnosisRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);
        $this->guardNotSigned($encounter, $request);

        $context = TenantContext::current();

        $diagnosis = Diagnosis::query()->create([
            'tenant_id' => $encounter->tenant_id,
            'encounter_id' => $encounter->getKey(),
            'code' => $request->validated('code'),
            'coding_system' => $request->validated('codingSystem'),
            'description' => $request->validated('description'),
            'diagnosis_type' => $request->validated('diagnosisType', Diagnosis::TYPE_PROVISIONAL),
            'is_primary' => $request->validated('isPrimary', false),
            'onset_date' => $request->validated('onsetDate'),
            'status' => Diagnosis::STATUS_ACTIVE,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'diagnosis.added',
            'diagnosis',
            $diagnosis->getKey(),
            ['encounterId' => $encounter->getKey(), 'code' => $diagnosis->code, 'diagnosisType' => $diagnosis->diagnosis_type],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $diagnosis->getKey(),
                'code' => $diagnosis->code,
                'codingSystem' => $diagnosis->coding_system,
                'description' => $diagnosis->description,
                'diagnosisType' => $diagnosis->diagnosis_type,
                'isPrimary' => $diagnosis->is_primary,
                'status' => $diagnosis->status,
            ],
            status: 201,
            request: $request,
        );
    }

    public function storePrescription(StorePrescriptionRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);
        $this->guardNotSigned($encounter, $request);

        $context = TenantContext::current();
        $prescriber = $this->currentProvider($encounter, $context);

        $prescription = DB::transaction(function () use ($request, $encounter, $context, $prescriber): Prescription {
            $prescription = Prescription::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'prescriber_staff_id' => $prescriber->getKey(),
                'status' => Prescription::STATUS_DRAFTED,
                'notes' => $request->validated('notes'),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);

            $lineNo = 1;
            foreach ($request->validated('lines') as $line) {
                $medication = Medication::query()
                    ->where('tenant_id', $encounter->tenant_id)
                    ->where('id', $line['medicationId'])
                    ->where('status', Medication::STATUS_ACTIVE)
                    ->first();

                if ($medication === null) {
                    throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Medication '.$line['medicationId'].' is not an active formulary item.', 422);
                }

                PrescriptionLine::query()->create([
                    'tenant_id' => $encounter->tenant_id,
                    'prescription_id' => $prescription->getKey(),
                    'medication_id' => $medication->getKey(),
                    'dose' => $line['dose'],
                    'route' => $line['route'],
                    'frequency' => $line['frequency'],
                    'duration' => $line['duration'] ?? null,
                    'quantity_minor' => $line['quantityMinor'] ?? null,
                    'instructions' => $line['instructions'] ?? null,
                    'status' => PrescriptionLine::STATUS_ORDERED,
                    'line_no' => $lineNo++,
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            return $prescription;
        });

        $this->audit->record(
            'prescription.drafted',
            'prescription',
            $prescription->getKey(),
            ['encounterId' => $encounter->getKey(), 'lineCount' => $prescription->lines()->count(), 'prescriberStaffId' => $prescriber->getKey()],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $prescription->getKey(),
                'status' => $prescription->status,
                'lineCount' => $prescription->lines()->count(),
                'lines' => $prescription->lines()->with('medication:id,generic_name,brand_name,strength')->get()->map(fn (PrescriptionLine $line): array => [
                    'id' => $line->getKey(),
                    'medication' => $line->medication ? ['id' => $line->medication->getKey(), 'genericName' => $line->medication->generic_name, 'brandName' => $line->medication->brand_name, 'strength' => $line->medication->strength] : null,
                    'dose' => $line->dose,
                    'route' => $line->route,
                    'frequency' => $line->frequency,
                    'duration' => $line->duration,
                    'quantityMinor' => $line->quantity_minor,
                    'instructions' => $line->instructions,
                    'status' => $line->status,
                ])->values(),
            ],
            status: 201,
            request: $request,
        );
    }

    /**
     * POST /encounters/{encounter}/sign — the provider signs the completed
     * record. Signed encounters are immutable history (DATABASE.md §3.17).
     */
    public function sign(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if ($encounter->status !== Encounter::STATUS_OPEN) {
            return Envelope::error(ErrorCodes::CONFLICT, 'Only an open encounter can be signed (current status: '.$encounter->status.').', 409, request: $request);
        }

        if (! $encounter->notes()->where('status', ClinicalNote::STATUS_SIGNED)->exists()) {
            return Envelope::error(ErrorCodes::CONFLICT, 'An encounter must contain at least one signed note before signing.', 409, request: $request);
        }

        $context = TenantContext::current();
        $provider = $this->currentProvider($encounter, $context);

        $encounter->status = Encounter::STATUS_SIGNED;
        $encounter->ended_at = now();
        $encounter->signed_by = $context->user?->getKey();
        $encounter->signed_at = now();
        $encounter->lock_version += 1;
        $encounter->save();

        if ($encounter->appointment_id !== null) {
            $appointment = $encounter->appointment;
            if ($appointment !== null && $appointment->status === Appointment::STATUS_IN_CONSULTATION) {
                $appointment->status = Appointment::STATUS_COMPLETED;
                $appointment->lock_version += 1;
                $appointment->save();
            }
        }

        $this->audit->record(
            'encounter.signed',
            'encounter',
            $encounter->getKey(),
            ['patientId' => $encounter->patient_id, 'providerStaffId' => $provider->getKey(), 'appointmentId' => $encounter->appointment_id],
            $request,
        );

        return Envelope::success(data: self::present($encounter), request: $request);
    }

    /**
     * GET /encounters/{encounter}/charges — posted charges on this visit.
     */
    public function charges(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: false);

        $charges = $encounter->charges()
            ->orderBy('charged_at')
            ->get()
            ->map(fn (Charge $charge): array => [
                'id' => $charge->getKey(),
                'sourceType' => $charge->source_type,
                'description' => $charge->description,
                'amountMinor' => $charge->amount_minor,
                'currency' => $charge->currency,
                'status' => $charge->status,
                'chargedAt' => $charge->charged_at?->toIso8601String(),
            ])
            ->values();

        return Envelope::success(data: $charges, request: $request);
    }

    /**
     * POST /encounters/{encounter}/invoice — build the bill from the
     * encounter's consultation charge + prescription line charges.
     */
    public function invoice(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if ($encounter->status !== Encounter::STATUS_SIGNED) {
            return Envelope::error(ErrorCodes::CONFLICT, 'Only a signed encounter can be billed.', 409, request: $request);
        }

        $context = TenantContext::current();

        $invoice = DB::transaction(function () use ($encounter, $context): Invoice {
            // Consultation charge from the appointment's service rate.
            $consultationCharge = Charge::query()
                ->where('tenant_id', $encounter->tenant_id)
                ->where('encounter_id', $encounter->getKey())
                ->where('source_type', Charge::SOURCE_ENCOUNTER)
                ->exists();

            if (! $consultationCharge) {
                $service = $encounter->appointment?->service;
                if ($service !== null && $service->default_charge_minor !== null) {
                    Charge::query()->create([
                        'tenant_id' => $encounter->tenant_id,
                        'facility_id' => $encounter->facility_id,
                        'patient_id' => $encounter->patient_id,
                        'source_type' => Charge::SOURCE_ENCOUNTER,
                        'encounter_id' => $encounter->getKey(),
                        'description' => $service->name.' — consultation',
                        'amount_minor' => $service->default_charge_minor,
                        'currency' => $service->currency ?? 'NPR',
                        ...Charge::resolveTaxFields($encounter->facility_id, 'opd'),
                        'status' => Charge::STATUS_POSTED,
                        'charged_at' => now(),
                        'created_by' => $context->user?->getKey(),
                    ]);
                }
            }

            // Prescription line charges: price × quantity (minor units).
            $alreadyCharged = Charge::query()
                ->where('tenant_id', $encounter->tenant_id)
                ->where('prescription_id', $encounter->prescriptions()->value('id'))
                ->exists();

            if (! $alreadyCharged) {
                foreach ($encounter->prescriptions()->with('lines.medication')->get() as $prescription) {
                    foreach ($prescription->lines as $line) {
                        if ($line->status !== PrescriptionLine::STATUS_ORDERED || $line->medication === null) {
                            continue;
                        }

                        // Cross-module guard: skip if a charge was already posted
                        // for this line (e.g. pharmacy dispensed it first).
                        $lineAlreadyCharged = Charge::query()
                            ->where('tenant_id', $encounter->tenant_id)
                            ->where('prescription_line_id', $line->getKey())
                            ->where('status', Charge::STATUS_POSTED)
                            ->exists();
                        if ($lineAlreadyCharged) {
                            continue;
                        }

                        $quantity = max(1, (int) ($line->quantity_minor ?? 1));
                        Charge::query()->create([
                            'tenant_id' => $encounter->tenant_id,
                            'facility_id' => $encounter->facility_id,
                            'patient_id' => $encounter->patient_id,
                            'source_type' => Charge::SOURCE_PRESCRIPTION,
                            'encounter_id' => $encounter->getKey(),
                            'prescription_id' => $prescription->getKey(),
                            'description' => $line->medication->generic_name.' ('.$line->medication->strength.') × '.$quantity,
                            'amount_minor' => $line->medication->price_minor * $quantity,
                            'currency' => $line->medication->currency,
                            ...Charge::resolveTaxFields($encounter->facility_id, 'pharmacy'),
                            'status' => Charge::STATUS_POSTED,
                            'charged_at' => now(),
                            'created_by' => $context->user?->getKey(),
                        ]);
                    }
                }
            }

            $chargeIds = $encounter->charges()
                ->where('status', Charge::STATUS_POSTED)
                ->pluck('id')
                ->all();

            if ($chargeIds === []) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This encounter has no charges to bill.', 409);
            }

            return $this->billing->issueInvoice(
                (string) $encounter->tenant_id,
                (string) $encounter->facility_id,
                (string) $encounter->patient_id,
                $chargeIds,
                $context->user?->getKey(),
            );
        });

        $this->audit->record(
            'invoice.issued',
            'invoice',
            $invoice->getKey(),
            ['patientId' => $encounter->patient_id, 'encounterId' => $encounter->getKey(), 'totalMinor' => $invoice->total_minor, 'lineCount' => $invoice->lines()->count()],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $invoice->getKey(),
                'invoiceNumber' => $invoice->invoice_number,
                'status' => $invoice->status,
                'totalMinor' => $invoice->total_minor,
                'totalTaxMinor' => $invoice->total_tax_minor,
                'paidMinor' => $invoice->paid_minor,
                'lines' => $invoice->lines()->orderBy('line_no')->get()->map(fn ($line): array => [
                    'description' => $line->description,
                    'amountMinor' => $line->amount_minor,
                    'taxMinor' => $line->tax_minor,
                ])->values(),
            ],
            status: 201,
            request: $request,
        );
    }

    /**
     * The provider for this encounter: the authenticated user's staff
     * profile, which must be the encounter's provider (clinical safety —
     * only the assigned clinician documents their own visit).
     */
    private function currentProvider(Encounter $encounter, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->getKey() !== $encounter->provider_staff_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'Only the encounter provider can document this visit.', 403);
        }

        return $staff;
    }

    private function guardNotSigned(Encounter $encounter, Request $request): void
    {
        if ($encounter->status !== Encounter::STATUS_OPEN) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Clinical content cannot be added to a signed encounter — amendment is the only path (later phase).',
                409,
            );
        }
    }

    /**
     * POST /encounters/{encounter}/discharge — the clinical close of a
     * signed visit (PRODUCT_REQUIREMENTS §6.7): only the encounter provider
     * may discharge, the encounter must be signed (the record is final), and
     * the transition signed → closed is a compare-and-swap on (status,
     * lock_version) — two concurrent discharges can never double-close.
     * IPD's structured discharge (diagnoses/procedures/medications sections)
     * is a later-phase plan; the OPD summary is captured here.
     */
    public function discharge(DischargeEncounterRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if ($encounter->status !== Encounter::STATUS_SIGNED) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Only a signed encounter can be discharged (current status: '.$encounter->status.').',
                409,
            );
        }

        $context = TenantContext::current();
        $provider = $this->currentProvider($encounter, $context);

        DB::transaction(function () use ($request, $encounter, $context): void {
            $updated = DB::table('encounters')
                ->where('id', $encounter->getKey())
                ->where('lock_version', $encounter->lock_version)
                ->where('status', Encounter::STATUS_SIGNED)
                ->update([
                    'status' => Encounter::STATUS_CLOSED,
                    'disposition' => $request->validated('disposition'),
                    'discharge_summary' => $request->validated('summary'),
                    'discharged_by' => $context->user?->getKey(),
                    'discharged_at' => now(),
                    'lock_version' => $encounter->lock_version + 1,
                    'updated_by' => $context->user?->getKey(),
                ]);

            if ($updated !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This encounter was concurrently modified; refresh and retry.', 409);
            }
        });

        $this->audit->record(
            'encounter.discharged',
            'encounter',
            $encounter->getKey(),
            ['patientId' => $encounter->patient_id, 'providerStaffId' => $provider->getKey(), 'appointmentId' => $encounter->appointment_id, 'disposition' => $request->validated('disposition')],
            $request,
        );

        return Envelope::success(data: self::present($encounter->fresh()), request: $request);
    }

    /**
     * GET /patients/{patient}/encounters — list encounters for a patient.
     */
    public function byPatient(Request $request, string $patientId): JsonResponse
    {
        $tenantId = TenantContext::current()->tenantId;
        $facilityId = $request->header('X-Swasthya-Facility') ?? $request->header('X-Facility-Id');

        $encounters = Encounter::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->with(['provider:id,full_name', 'service:id,name'])
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (Encounter $e) => [
                'id' => $e->getKey(),
                'type' => $e->type,
                'status' => $e->status,
                'providerName' => $e->provider?->full_name,
                'serviceName' => $e->service?->name,
                'startedAt' => $e->started_at?->toIso8601String(),
            ]);

        return Envelope::success(data: $encounters, request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Encounter $encounter): array
    {
        return [
            'id' => $encounter->getKey(),
            'facilityId' => $encounter->facility_id,
            'patientId' => $encounter->patient_id,
            'appointmentId' => $encounter->appointment_id,
            'providerStaffId' => $encounter->provider_staff_id,
            'type' => $encounter->type,
            'status' => $encounter->status,
            'startedAt' => $encounter->started_at?->toIso8601String(),
            'endedAt' => $encounter->ended_at?->toIso8601String(),
            'signedAt' => $encounter->signed_at?->toIso8601String(),
            'disposition' => $encounter->disposition,
            'dischargeSummary' => $encounter->discharge_summary,
            'dischargedAt' => $encounter->discharged_at?->toIso8601String(),
            'lockVersion' => $encounter->lock_version,
        ];
    }
}
