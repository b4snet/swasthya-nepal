<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StoreContactRequest;
use App\Http\Requests\Patient\UpdateContactRequest;
use App\Models\Patient;
use App\Models\PatientContact;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\PatientTimeline;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patient contacts (DATABASE.md §3.13): phone, email, address, emergency
 * contacts. One active primary per (patient, type) — promoted primaries
 * supersede the previous holder. Contacts are never deleted: superseded is a
 * status (care continuity).
 */
final class PatientContactController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PatientTimeline $timeline,
    ) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $contacts = $patient->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get()
            ->map(fn (PatientContact $contact): array => self::present($contact))
            ->values();

        return Envelope::success(data: $contacts, request: $request);
    }

    public function store(StoreContactRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();
        $isPrimary = (bool) $request->validated('isPrimary', false);

        $contact = DB::transaction(function () use ($request, $patient, $context, $isPrimary): PatientContact {
            $this->demotePrimaries($patient, (string) $request->validated('type'));

            return PatientContact::query()->create([
                'tenant_id' => $patient->tenant_id,
                'patient_id' => $patient->getKey(),
                'type' => $request->validated('type'),
                'value' => $request->validated('value'),
                'address' => $request->validated('address'),
                'contact_person' => $request->validated('contactPerson'),
                'is_primary' => $isPrimary,
                'status' => PatientContact::STATUS_ACTIVE,
                'created_by' => $context->user?->getKey(),
            ]);
        });

        $this->audit->record(
            'patient.contact.added',
            'patient_contact',
            $contact->getKey(),
            ['patientId' => $patient->getKey(), 'type' => $contact->type, 'isPrimary' => $contact->is_primary],
            $request,
        );
        $this->timeline->record($patient, 'patient.contact_added', ['type' => $contact->type, 'isPrimary' => $contact->is_primary], $request);

        return Envelope::success(data: self::present($contact), status: 201, request: $request);
    }

    public function update(UpdateContactRequest $request, PatientContact $contact): JsonResponse
    {
        AccessCheck::patientChild($contact, write: true);

        $context = TenantContext::current();
        $patient = $contact->patient;

        $wasPrimary = $contact->is_primary;
        $changes = [];

        if ($request->has('value')) {
            $changes['value'] = [$contact->value, $request->validated('value')];
            $contact->value = $request->validated('value');
        }

        if ($request->has('address')) {
            $changes['address'] = [true, $request->validated('address') !== null];
            $contact->address = $request->validated('address');
        }

        if ($request->has('contactPerson')) {
            $changes['contactPerson'] = [true, $request->validated('contactPerson')];
            $contact->contact_person = $request->validated('contactPerson');
        }

        if ($request->has('isPrimary')) {
            $changes['isPrimary'] = [$wasPrimary, $request->validated('isPrimary')];
            $contact->is_primary = (bool) $request->validated('isPrimary');
        }

        if ($request->has('status')) {
            $changes['status'] = [$contact->status, $request->validated('status')];
            $contact->status = $request->validated('status');
        }

        DB::transaction(function () use ($patient, $contact, $wasPrimary, $context): void {
            if ($contact->is_primary && ! $wasPrimary && $contact->status === PatientContact::STATUS_ACTIVE) {
                $this->demotePrimaries($patient, (string) $contact->type, except: $contact->getKey());
            }

            $contact->updated_by = $context->user?->getKey();
            $contact->save();
        });

        $this->audit->record('patient.contact.updated', 'patient_contact', $contact->getKey(), ['changes' => $changes], $request);
        $this->timeline->record($patient, 'patient.contact_updated', ['type' => $contact->type], $request);

        return Envelope::success(data: self::present($contact), request: $request);
    }

    private function demotePrimaries(Patient $patient, string $type, ?string $except = null): void
    {
        $query = $patient->contacts()
            ->where('type', $type)
            ->where('status', PatientContact::STATUS_ACTIVE)
            ->where('is_primary', true);

        if ($except !== null) {
            $query->where('id', '!=', $except);
        }

        $query->update(['is_primary' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(PatientContact $contact): array
    {
        return [
            'id' => $contact->getKey(),
            'type' => $contact->type,
            'value' => $contact->value,
            'address' => $contact->address,
            'contactPerson' => $contact->contact_person,
            'isPrimary' => $contact->is_primary,
            'status' => $contact->status,
        ];
    }
}
