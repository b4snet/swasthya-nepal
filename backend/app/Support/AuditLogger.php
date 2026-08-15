<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\Branch;
use App\Models\Charge;
use App\Models\ClinicalNote;
use App\Models\Department;
use App\Models\Encounter;
use App\Models\FollowUp;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Location;
use App\Models\Medication;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Room;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use App\Models\Ward;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONLY writer of audit_events (DATABASE.md §3.36, MASTER_RULES.md §19).
 *
 *  - Every event records its full context: tenant (null for platform
 *    events), facility where relevant, actor (with email), action,
 *    resource, payload, IP, and the request's correlation id.
 *  - The hash chain is serialized with a transaction-scoped advisory lock so
 *    concurrent writers cannot fork it: each event_hash covers the previous
 *    event's hash plus this event's canonical payload.
 *  - No PHI in payloads: audit events carry facts and references, never
 *    clinical content (MASTER_RULES.md §10.5, OBSERVABILITY.md §17).
 */
final class AuditLogger
{
    /**
     * Advisory lock key namespacing the audit chain (bigint). Serializes
     * chain appends across connections within a transaction.
     */
    private const CHAIN_LOCK_KEY = 41_090_701; // crc32('swasthya.audit_events') & 0x7fffffff

    /**
     * Facility-scoped resources: when the actor's context has no facility
     * (org/platform scope), the event's facility_id is still derived from
     * the affected resource so facility-scoped auditors can read it
     * (DATABASE.md §3.36).
     *
     * 'facility_settings' is special: its resource_id IS the facility id,
     * so the facility is taken directly. null = resource id is the facility.
     *
     * @var array<string, class-string<Model>|null>
     */
    private const FACILITY_SCOPED_RESOURCES = [
        'department' => Department::class,
        'branch' => Branch::class,
        'location' => Location::class,
        'ward' => Ward::class,
        'room' => Room::class,
        'bed' => Bed::class,
        'staff' => Staff::class,
        'service' => Service::class,
        'facility_settings' => null,
        // Phase 6/7 — front desk and clinical spine.
        'schedule_template' => ScheduleTemplate::class,
        'schedule_exception' => ScheduleException::class,
        'appointment' => Appointment::class,
        'encounter' => Encounter::class,
        'clinical_note' => ClinicalNote::class,
        'medication' => Medication::class,
        'prescription' => Prescription::class,
        'charge' => Charge::class,
        'invoice' => Invoice::class,
        'payment' => Payment::class,
        // Phase 3 slice 2 — laboratory & radiology.
        'lab_test' => LabTest::class,
        'lab_order' => LabOrder::class,
        // Phase 3 slice 3 — pharmacy inventory ledger.
        'inventory_item' => InventoryItem::class,
        'inventory_movement' => InventoryMovement::class,
        // Phase 3 slice 4 — discharge & follow-up.
        'follow_up' => FollowUp::class,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $action,
        string $resourceType,
        ?string $resourceId = null,
        array $payload = [],
        ?Request $request = null,
        ?User $actor = null,
        ?string $tenantId = null,
        ?string $facilityId = null,
        string $actorType = AuditEvent::ACTOR_USER,
        ?CarbonInterface $occurredAt = null,
        ?string $actorEmail = null,
        ?string $supportSessionId = null,
    ): AuditEvent {
        $request ??= request();
        $context = TenantContext::current();
        $actor ??= $context->user;
        $tenantId ??= $context->tenantId();
        $facilityId ??= $context->facilityId();
        $occurredAt ??= now();
        $supportSessionId ??= $context->supportSessionId;

        // Facility-scoped resources always carry their facility in the event
        // even when the actor's context is org/platform-scoped. array_key_exists
        // (NOT isset): 'facility_settings' maps to null, and isset(null) is false.
        if ($facilityId === null && $resourceId !== null && array_key_exists($resourceType, self::FACILITY_SCOPED_RESOURCES)) {
            $modelClass = self::FACILITY_SCOPED_RESOURCES[$resourceType];
            $facilityId = $modelClass === null
                ? $resourceId // facility_settings: resource id IS the facility
                : self::resolveFacilityFromResource($modelClass, $resourceId);
        }
        $actorEmail ??= $actor?->email;

        $correlationId = $request?->attributes->get('correlation_id');

        $correlationId = is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : (string) Str::uuid();

        return DB::transaction(function () use (
            $action, $resourceType, $resourceId, $payload, $request, $actor,
            $tenantId, $facilityId, $actorType, $occurredAt, $correlationId, $actorEmail,
            $supportSessionId,
        ): AuditEvent {
            // Serialize chain appends; the lock dies with the transaction.
            DB::statement('select pg_advisory_xact_lock(?)', [self::CHAIN_LOCK_KEY]);

            // Explicit ordering: occurred_at, then the time-ordered UUIDv7 id
            // as the deterministic tie-break for the chain's predecessor.
            $previousHash = AuditEvent::query()
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->value('event_hash');

            $event = new AuditEvent;
            // The id must exist BEFORE the hash is computed — chainPayload
            // covers it, and the row must verify identically on read-back.
            $event->setAttribute('id', (string) Str::uuid7());
            $event->forceFill([
                'tenant_id' => $tenantId,
                'occurred_at' => $occurredAt,
                'actor_type' => $actorType,
                'actor_id' => $actor?->getKey(),
                'actor_email' => $actorEmail,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'facility_id' => $facilityId,
                'support_session_id' => $supportSessionId,
                'payload' => $payload,
                'ip_address' => $request?->ip(),
                'correlation_id' => $correlationId,
                'prev_hash' => $previousHash,
            ]);
            $event->event_hash = hash('sha256', ($previousHash ?? '').'|'.$event->chainPayload());
            $event->save();

            return $event;
        });
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private static function resolveFacilityFromResource(string $modelClass, string $resourceId): ?string
    {
        $model = $modelClass::query()->find($resourceId);
        $facilityId = $model?->getAttribute('facility_id');

        return is_string($facilityId) ? $facilityId : null;
    }
}
