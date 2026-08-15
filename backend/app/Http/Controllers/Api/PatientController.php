<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\MergePatientsRequest;
use App\Http\Requests\Patient\SearchPatientRequest;
use App\Http\Requests\Patient\StorePatientRequest;
use App\Http\Requests\Patient\UpdatePatientRequest;
use App\Models\Consent;
use App\Models\InsurancePolicy;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Models\PatientIdentifier;
use App\Services\DuplicateDetector;
use App\Services\MrnIssuer;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\PatientTimeline;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The master patient record (DATABASE.md §3.11, API_CONTRACTS.md §21.7).
 *
 *  - Registration runs duplicate detection server-side and returns
 *    candidates in meta.duplicates — never auto-merge.
 *  - MRN is issued inside the create transaction from the per-tenant atomic
 *    counter (MrnIssuer).
 *  - Reads: show() is audited (patient.viewed); out-of-scope reads are 404.
 *  - Updates are optimistic-locked on lock_version.
 *  - Merge is the only identity-resolution path: transactional, reason
 *    required, fully audited, children reassigned with collision handling.
 */
final class PatientController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PatientTimeline $timeline,
        private readonly MrnIssuer $mrnIssuer,
        private readonly DuplicateDetector $duplicates,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();

        $query = Patient::query()
            ->where('tenant_id', $organization->getKey())
            ->orderByDesc('created_at');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $patients = $query->get()
            ->map(fn (Patient $patient): array => self::present($patient))
            ->values();

        return Envelope::success(data: $patients, request: $request);
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        // Duplicate detection BEFORE insert: identifier hashes and name+dob.
        $identifierHashes = [];
        foreach ($request->validated('identifiers', []) as $identifier) {
            $identifierHashes[$identifier['type']] = PatientIdentifier::hashValue($identifier['value']);
        }

        $duplicates = $this->duplicates->candidates(
            (string) $context->tenantId(),
            (string) $request->validated('fullName'),
            $request->validated('dateOfBirth'),
            $identifierHashes,
        );

        $patient = DB::transaction(function () use ($request, $context, $facility): Patient {
            $patient = Patient::query()->create([
                'tenant_id' => $context->tenantId(),
                'facility_id' => $facility->getKey(),
                'mrn' => $this->mrnIssuer->issue((string) $context->tenantId()),
                'full_name' => $request->validated('fullName'),
                'date_of_birth' => $request->validated('dateOfBirth'),
                'sex' => $request->validated('sex'),
                'blood_group' => $request->validated('bloodGroup'),
                'status' => Patient::STATUS_ACTIVE,
                'consent_summary' => [],
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);

            // Primary contacts captured with registration.
            if ($request->filled('phone')) {
                PatientContact::query()->create([
                    'tenant_id' => $patient->tenant_id,
                    'patient_id' => $patient->getKey(),
                    'type' => PatientContact::TYPE_PHONE,
                    'value' => $request->validated('phone'),
                    'is_primary' => true,
                    'status' => PatientContact::STATUS_ACTIVE,
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            if ($request->filled('email')) {
                PatientContact::query()->create([
                    'tenant_id' => $patient->tenant_id,
                    'patient_id' => $patient->getKey(),
                    'type' => PatientContact::TYPE_EMAIL,
                    'value' => $request->validated('email'),
                    'is_primary' => true,
                    'status' => PatientContact::STATUS_ACTIVE,
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            if ($request->filled('address')) {
                PatientContact::query()->create([
                    'tenant_id' => $patient->tenant_id,
                    'patient_id' => $patient->getKey(),
                    'type' => PatientContact::TYPE_ADDRESS,
                    'address' => $request->validated('address'),
                    'is_primary' => true,
                    'status' => PatientContact::STATUS_ACTIVE,
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            if ($request->filled('emergencyContact')) {
                $emergency = $request->validated('emergencyContact');
                PatientContact::query()->create([
                    'tenant_id' => $patient->tenant_id,
                    'patient_id' => $patient->getKey(),
                    'type' => PatientContact::TYPE_EMERGENCY,
                    'value' => $emergency['phone'],
                    'contact_person' => ['name' => $emergency['name'], 'relation' => $emergency['relation']],
                    'is_primary' => true,
                    'status' => PatientContact::STATUS_ACTIVE,
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            foreach ($request->validated('identifiers', []) as $identifierData) {
                $identifier = new PatientIdentifier;
                $identifier->tenant_id = $patient->tenant_id;
                $identifier->patient_id = $patient->getKey();
                $identifier->type = $identifierData['type'];
                $identifier->value = $identifierData['value']; // mutator: encrypt + hash
                $identifier->issuing_country = $identifierData['issuingCountry'] ?? null;
                $identifier->status = PatientIdentifier::STATUS_ACTIVE;
                $identifier->created_by = $context->user?->getKey();
                $identifier->save();
            }

            return $patient;
        });

        $this->audit->record(
            'patient.created',
            'patient',
            $patient->getKey(),
            ['mrn' => $patient->mrn, 'facilityId' => $patient->facility_id, 'duplicateCandidates' => $duplicates->count()],
            $request,
        );
        $this->timeline->record($patient, 'patient.registered', ['mrn' => $patient->mrn], $request);

        return Envelope::success(
            data: self::present($patient),
            status: 201,
            meta: ['duplicates' => $duplicates->all()],
            request: $request,
            headers: [
                'Location' => '/api/v1/patients/'.$patient->getKey(),
            ],
        );
    }

    public function show(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        // Record views are audited (DATABASE.md §3.11) — facts only, no PHI.
        $this->audit->record('patient.viewed', 'patient', $patient->getKey(), ['mrn' => $patient->mrn], $request);

        return Envelope::success(data: self::present($patient), request: $request);
    }

    public function update(UpdatePatientRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $clientVersion = (int) $request->validated('lockVersion');
        $changes = [];

        foreach (['full_name', 'date_of_birth', 'sex', 'blood_group'] as $field) {
            $input = match ($field) {
                'full_name' => 'fullName',
                'date_of_birth' => 'dateOfBirth',
                'sex' => 'sex',
                default => 'bloodGroup',
            };

            if ($request->has($input)) {
                $changes[$field] = [$patient->getAttribute($field), $request->validated($input)];
                $patient->setAttribute($field, $request->validated($input));
            }
        }

        $context = TenantContext::current();
        $affected = DB::table('patients')
            ->where('id', $patient->getKey())
            ->where('lock_version', $clientVersion)
            ->update([
                'full_name' => $patient->full_name,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'sex' => $patient->sex,
                'blood_group' => $patient->blood_group,
                'lock_version' => $patient->lock_version + 1,
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return Envelope::error(
                'LOCK_CONFLICT',
                'This patient was changed by someone else. Reload and retry.',
                409,
                request: $request,
            );
        }

        $patient->lock_version += 1;

        $this->audit->record('patient.updated', 'patient', $patient->getKey(), ['changes' => $changes], $request);
        $this->timeline->record($patient, 'patient.updated', ['changed' => array_keys($changes)], $request);

        return Envelope::success(data: self::present($patient), request: $request);
    }

    public function search(SearchPatientRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $q = trim((string) $request->validated('q'));

        $query = Patient::query()
            ->select(
                'id',
                'mrn',
                'full_name',
                'date_of_birth',
                'sex',
                'status',
                'facility_id',
                DB::raw('similarity(lower(full_name), ?) as score')
            )
            ->where('tenant_id', $context->tenantId())
            ->where('status', 'active')
            ->where(function ($where) use ($q): void {
                $where->whereRaw('lower(full_name) like ?', ['%'.strtolower($q).'%'])
                    ->orWhereRaw('lower(mrn) like ?', [strtolower($q).'%']);
            })
            ->addBinding(strtolower($q), 'select')
            ->orderByDesc('score')
            ->limit(20);

        // Facility-scoped principals search their facility; org/platform
        // principals search the tenant (API_CONTRACTS.md §21.7).
        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $results = $query->get()
            ->map(fn ($patient): array => [
                'id' => (string) $patient->id,
                'mrn' => (string) $patient->mrn,
                'fullName' => (string) $patient->full_name,
                'dateOfBirth' => (string) $patient->date_of_birth,
                'sex' => (string) $patient->sex,
                'facilityId' => (string) $patient->facility_id,
                'score' => round((float) $patient->score, 4),
            ])
            ->values();

        $this->audit->record(
            'patient.searched',
            'patient_search',
            null,
            ['resultCount' => $results->count()],
            $request,
        );

        return Envelope::success(
            data: $results,
            meta: [
                'search' => [
                    'hint' => count($results) > 0
                        ? count($results).' candidate(s) found — confirm identity before opening.'
                        : 'No candidates found.',
                ],
            ],
            request: $request,
        );
    }

    public function merge(MergePatientsRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();
        $target = Patient::query()->find($request->validated('targetPatientId'));
        AccessCheck::scoped($target, write: true);

        if ($patient->getKey() === $target->getKey()) {
            return Envelope::error('VALIDATION_ERROR', 'A patient cannot be merged into itself.', 422, request: $request);
        }

        if ($patient->status !== Patient::STATUS_ACTIVE) {
            return Envelope::error('CONFLICT', 'Only an active patient can be merged.', 409, request: $request);
        }

        if ($target->status !== Patient::STATUS_ACTIVE) {
            return Envelope::error('CONFLICT', 'The target patient must be active.', 409, request: $request);
        }

        $reason = (string) $request->validated('reason');
        $moved = [];

        DB::transaction(function () use ($patient, $target, $context, &$moved): void {
            $moved = $this->reassignChildren($patient, $target);

            $patient->status = Patient::STATUS_MERGED;
            $patient->merge_into_patient_id = $target->getKey();
            $patient->updated_by = $context->user?->getKey();
            $patient->save();

            $this->refreshConsentSummary($target);
        });

        $this->audit->record(
            'patient.merged',
            'patient',
            $patient->getKey(),
            [
                'targetPatientId' => $target->getKey(),
                'reason' => $reason,
                'moved' => $moved,
            ],
            $request,
        );
        $this->timeline->record($patient, 'patient.merged', ['targetPatientId' => $target->getKey(), 'reason' => $reason], $request);
        $this->timeline->record($target, 'patient.merge_received', ['sourcePatientId' => $patient->getKey(), 'reason' => $reason], $request);

        return Envelope::success(data: self::present($target), request: $request);
    }

    public function timeline(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $entries = $patient->timeline()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($entry): array => [
                'id' => $entry->getKey(),
                'occurredAt' => $entry->occurred_at?->toIso8601String(),
                'eventType' => $entry->event_type,
                'summary' => $entry->summary,
            ])
            ->values();

        return Envelope::success(data: $entries, request: $request);
    }

    /**
     * Reassign the source patient's children to the survivor, resolving
     * one-active-per-* collisions by keeping the survivor's record and
     * retiring the source's (history stays with the source).
     *
     * @return array<string, int>
     */
    private function reassignChildren(Patient $source, Patient $target): array
    {
        $moved = [];

        // Documents and timeline have no uniqueness conflicts.
        $moved['documents'] = $source->documents()->update(['patient_id' => $target->getKey()]);
        $moved['timeline'] = $source->timeline()->update(['patient_id' => $target->getKey()]);

        // Identifiers: skip a type the survivor already holds actively.
        $targetActiveTypes = PatientIdentifier::query()
            ->where('tenant_id', $target->tenant_id)
            ->where('patient_id', $target->getKey())
            ->where('status', 'active')
            ->pluck('type');

        $moved['identifiers'] = 0;
        foreach ($source->identifiers()->where('status', 'active')->get() as $identifier) {
            if ($targetActiveTypes->contains($identifier->type)) {
                $identifier->status = PatientIdentifier::STATUS_SUPERSEDED;
                $identifier->save();

                continue;
            }
            $identifier->patient_id = $target->getKey();
            $identifier->save();
            $targetActiveTypes->push($identifier->type);
            $moved['identifiers']++;
        }

        // Contacts: keep the survivor's primaries; demote moved ones.
        $targetPrimaryTypes = PatientContact::query()
            ->where('tenant_id', $target->tenant_id)
            ->where('patient_id', $target->getKey())
            ->where('status', 'active')
            ->where('is_primary', true)
            ->pluck('type');

        $moved['contacts'] = 0;
        foreach ($source->contacts()->where('status', 'active')->get() as $contact) {
            if ($targetPrimaryTypes->contains($contact->type) && $contact->is_primary) {
                $contact->is_primary = false;
            }
            $contact->patient_id = $target->getKey();
            $contact->save();
            $moved['contacts']++;
        }

        // Policies: skip a payer the survivor already holds actively.
        $targetActivePayers = $target->insurancePolicies()
            ->where('status', 'active')
            ->pluck('payer_id');

        $moved['policies'] = 0;
        foreach ($source->insurancePolicies()->where('status', 'active')->get() as $policy) {
            if ($targetActivePayers->contains($policy->payer_id)) {
                $policy->status = InsurancePolicy::STATUS_CANCELLED;
                $policy->save();

                continue;
            }
            $policy->patient_id = $target->getKey();
            $policy->save();
            $targetActivePayers->push($policy->payer_id);
            $moved['policies']++;
        }

        // Consents: keep the survivor's active consent per type.
        $targetActiveTypes = $target->consents()
            ->where('status', 'active')
            ->pluck('consent_type');

        $moved['consents'] = 0;
        foreach ($source->consents()->where('status', 'active')->get() as $consent) {
            if ($targetActiveTypes->contains($consent->consent_type)) {
                $consent->status = Consent::STATUS_EXPIRED;
                $consent->save();

                continue;
            }
            $consent->patient_id = $target->getKey();
            $consent->save();
            $targetActiveTypes->push($consent->consent_type);
            $moved['consents']++;
        }

        return $moved;
    }

    private function refreshConsentSummary(Patient $patient): void
    {
        // Highest version per type wins (ordered ascending so the latest
        // overwrites when pluck keys collide).
        $summary = $patient->consents()
            ->orderBy('version')
            ->get()
            ->pluck('status', 'consent_type')
            ->all();

        $patient->consent_summary = $summary;
        $patient->save();
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Patient $patient): array
    {
        return [
            'id' => $patient->getKey(),
            'mrn' => $patient->mrn,
            'facilityId' => $patient->facility_id,
            'fullName' => $patient->full_name,
            'dateOfBirth' => $patient->date_of_birth?->toDateString(),
            'sex' => $patient->sex,
            'bloodGroup' => $patient->blood_group,
            'status' => $patient->status,
            'createdAt' => $patient->created_at?->toIso8601String(),
            'updatedAt' => $patient->updated_at?->toIso8601String(),
        ];
    }
}
