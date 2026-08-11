<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StoreIdentifierRequest;
use App\Models\Patient;
use App\Models\PatientIdentifier;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\PatientTimeline;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patient identifiers (DATABASE.md §3.12): encrypted at rest, hashed for
 * duplicate detection.
 *
 *  - Same (patient, type) again → the prior active one is superseded.
 *  - Same (tenant, type, hash) on ANOTHER patient → 409 RESOURCE_EXISTS
 *    with the candidate surfaced — never silently re-used.
 */
final class PatientIdentifierController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PatientTimeline $timeline,
    ) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $identifiers = $patient->identifiers()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PatientIdentifier $identifier): array => [
                'id' => $identifier->getKey(),
                'type' => $identifier->type,
                'value' => $identifier->value_encrypted, // decrypted by cast
                'issuingCountry' => $identifier->issuing_country,
                'isVerified' => $identifier->is_verified,
                'status' => $identifier->status,
            ])
            ->values();

        return Envelope::success(data: $identifiers, request: $request);
    }

    public function store(StoreIdentifierRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();
        $type = (string) $request->validated('type');
        $hash = PatientIdentifier::hashValue((string) $request->validated('value'));

        // Same value already active on ANOTHER patient → duplicate candidate.
        $other = PatientIdentifier::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('type', $type)
            ->where('value_hash', $hash)
            ->where('status', 'active')
            ->where('patient_id', '!=', $patient->getKey())
            ->with('patient:id,mrn,full_name')
            ->first();

        if ($other !== null) {
            return Envelope::error(
                'RESOURCE_EXISTS',
                'This identifier is already registered to another patient — verify before proceeding.',
                409,
                details: [
                    'candidate' => [
                        'id' => $other->patient->getKey(),
                        'mrn' => $other->patient->mrn,
                        'fullName' => $other->patient->full_name,
                    ],
                ],
                request: $request,
            );
        }

        $superseded = null;
        $identifier = DB::transaction(function () use ($patient, $type, $context, $request, &$superseded): PatientIdentifier {
            $existing = $patient->identifiers()
                ->where('type', $type)
                ->where('status', 'active')
                ->first();

            if ($existing !== null) {
                $existing->status = PatientIdentifier::STATUS_SUPERSEDED;
                $existing->save();
                $superseded = $existing->getKey();
            }

            $identifier = new PatientIdentifier;
            $identifier->tenant_id = $patient->tenant_id;
            $identifier->patient_id = $patient->getKey();
            $identifier->type = $type;
            $identifier->value = (string) $request->validated('value'); // mutator: encrypt + hash
            $identifier->issuing_country = $request->validated('issuingCountry');
            $identifier->status = PatientIdentifier::STATUS_ACTIVE;
            $identifier->created_by = $context->user?->getKey();
            $identifier->save();

            return $identifier;
        });

        $this->audit->record(
            'patient.identifier.added',
            'patient_identifier',
            $identifier->getKey(),
            ['patientId' => $patient->getKey(), 'type' => $type, 'supersededId' => $superseded],
            $request,
        );
        $this->timeline->record($patient, 'patient.identifier_added', ['type' => $type, 'supersededId' => $superseded], $request);

        return Envelope::success(
            data: [
                'id' => $identifier->getKey(),
                'type' => $identifier->type,
                'value' => $identifier->value_encrypted,
                'issuingCountry' => $identifier->issuing_country,
                'status' => $identifier->status,
            ],
            status: 201,
            request: $request,
        );
    }
}
