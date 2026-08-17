<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AiDraft;
use App\Models\AiFeature;
use App\Models\AnesthesiaRecord;
use App\Models\Appointment;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetTransfer;
use App\Models\AttendanceRecord;
use App\Models\AuditEvent;
use App\Models\Bed;
use App\Models\BloodUnit;
use App\Models\Branch;
use App\Models\CdssCheckResult;
use App\Models\CdssRule;
use App\Models\Charge;
use App\Models\ChecklistItem;
use App\Models\ClinicalNote;
use App\Models\CompatibilityResult;
use App\Models\CriticalCareNote;
use App\Models\CriticalValueEvent;
use App\Models\Crossmatch;
use App\Models\Dashboard;
use App\Models\DashboardKpi;
use App\Models\Department;
use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\Donation;
use App\Models\Donor;
use App\Models\EgressDestination;
use App\Models\Encounter;
use App\Models\ErEvent;
use App\Models\ErRegistration;
use App\Models\FollowUp;
use App\Models\IcuAdmission;
use App\Models\IcuAlert;
use App\Models\IcuBed;
use App\Models\IcuObservationSet;
use App\Models\InsuranceClaim;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Invoice;
use App\Models\IotReading;
use App\Models\KpiDefinition;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\MaintenanceSchedule;
use App\Models\MarEntry;
use App\Models\Medication;
use App\Models\MetricSnapshot;
use App\Models\NursingNote;
use App\Models\OauthPartner;
use App\Models\OauthPartnerToken;
use App\Models\Payment;
use App\Models\PayrollExport;
use App\Models\PharmacyReturn;
use App\Models\PortalAccessGrant;
use App\Models\PortalAccount;
use App\Models\PortalSession;
use App\Models\Position;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\Procedure;
use App\Models\ProcedureRequest;
use App\Models\ReactionReport;
use App\Models\RecoveryRecord;
use App\Models\RefundRequest;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Room;
use App\Models\Roster;
use App\Models\RpmAlert;
use App\Models\RpmDevice;
use App\Models\RpmReading;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Settlement;
use App\Models\ShiftTemplate;
use App\Models\Specimen;
use App\Models\Staff;
use App\Models\StockBatch;
use App\Models\SurgicalEvent;
use App\Models\SurgicalTeamMember;
use App\Models\Teleconsult;
use App\Models\Theatre;
use App\Models\TransferEvent;
use App\Models\Transfusion;
use App\Models\TriageAssignment;
use App\Models\TriageScale;
use App\Models\User;
use App\Models\VideoSession;
use App\Models\VitalObservation;
use App\Models\Ward;
use App\Models\WarningScore;
use App\Models\WorkOrder;
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
        // Phase 3 slice 17 — pharmacy batch/expiry + dual verification.
        'stock_batch' => StockBatch::class,
        'prescription_line' => PrescriptionLine::class,
        // Phase 3 slice 8 — pharmacy returns & reversals.
        'pharmacy_return' => PharmacyReturn::class,
        // Phase 3 slice 4 — discharge & follow-up.
        'follow_up' => FollowUp::class,
        // Phase 3 slice 5 — billing refunds & adjustments.
        'refund_request' => RefundRequest::class,
        // Phase 3 slice 18 — deposits, daily settlements, insurance claims.
        'deposit' => Deposit::class,
        'deposit_allocation' => DepositAllocation::class,
        'settlement' => Settlement::class,
        // insurance_claim is TENANT-tier (no facility_id) — the resolved
        // facility is null, so the event lands at tenant level (§3.35).
        'insurance_claim' => InsuranceClaim::class,
        // Phase 3 slice 6 — IPD admission/discharge.
        'admission' => Admission::class,
        // Phase 3 slice 7 — laboratory critical-value escalation.
        'critical_value_event' => CriticalValueEvent::class,
        // Phase 3 slice 15 — specimen custody (collection → … → completed /
        // rejected, WHO/WHEN at every step).
        'specimen' => Specimen::class,
        // Phase 3 slice 13 — the remaining documented IPD workflow:
        // audited transfers, nursing notes, MAR, and vital observations.
        'transfer_event' => TransferEvent::class,
        'nursing_note' => NursingNote::class,
        'mar_entry' => MarEntry::class,
        'vital_observation' => VitalObservation::class,
        // Phase 3 slice 14 — Emergency: registration, triage, events.
        'er_registration' => ErRegistration::class,
        'triage_scale' => TriageScale::class,
        'triage_assignment' => TriageAssignment::class,
        'er_event' => ErEvent::class,
        // Phase 3 slice 16 — Radiology: modality catalog, studies, reports,
        // and DICOM references.
        'modality' => Modality::class,
        'study' => Study::class,
        'radiology_report' => RadiologyReport::class,
        'image_reference' => ImageReference::class,
        // Phase 3 slice 19 — HR (positions, shifts, rosters, attendance,
        // leave, payroll exports) and Assets (categories, assets, transfers,
        // maintenance, work orders, IoT-ready readings). All facility-scoped.
        'position' => Position::class,
        'shift_template' => ShiftTemplate::class,
        'roster' => Roster::class,
        'attendance_record' => AttendanceRecord::class,
        'leave_type' => LeaveType::class,
        'leave_request' => LeaveRequest::class,
        'payroll_export' => PayrollExport::class,
        'asset_category' => AssetCategory::class,
        'asset' => Asset::class,
        'asset_transfer' => AssetTransfer::class,
        'maintenance_schedule' => MaintenanceSchedule::class,
        'work_order' => WorkOrder::class,
        'iot_reading' => IotReading::class,
        // Phase 3 slice 20 — OT (PRODUCT_REQUIREMENTS §6.10): scheduling,
        // procedures, team, anesthesia, events, checklists, recovery. All
        // facility-scoped; surgical records are medico-legal documents.
        'theatre' => Theatre::class,
        'procedure_request' => ProcedureRequest::class,
        'procedure' => Procedure::class,
        'surgical_team_member' => SurgicalTeamMember::class,
        'anesthesia_record' => AnesthesiaRecord::class,
        'surgical_event' => SurgicalEvent::class,
        'checklist_item' => ChecklistItem::class,
        'recovery_record' => RecoveryRecord::class,
        // Phase 3 slice 20 — ICU (PRODUCT_REQUIREMENTS §6.11): beds,
        // admissions, observations, computed scores, alerts, documentation.
        'icu_bed' => IcuBed::class,
        'icu_admission' => IcuAdmission::class,
        'icu_observation_set' => IcuObservationSet::class,
        'warning_score' => WarningScore::class,
        'icu_alert' => IcuAlert::class,
        'critical_care_note' => CriticalCareNote::class,
        // Phase 3 slice 20 — Blood Bank (PRODUCT_REQUIREMENTS §6.12):
        // donors (PII-protected), donations, units, compatibility,
        // crossmatch, transfusions (dual verification), reactions.
        'donor' => Donor::class,
        'donation' => Donation::class,
        'blood_unit' => BloodUnit::class,
        'compatibility_result' => CompatibilityResult::class,
        'crossmatch' => Crossmatch::class,
        'transfusion' => Transfusion::class,
        'reaction_report' => ReactionReport::class,
        // Phase 3 slice 21 — Analytics and Reporting (PRODUCT REQUIREMENTS
        // §6.19, DATABASE.md §3.51): versioned KPI definitions, observed
        // snapshots, dashboards, and the audited report/export surface.
        // Payloads carry facts only — ids, versions, counts, timestamps,
        // formats — never PHI or row contents.
        'kpi_definition' => KpiDefinition::class,
        'metric_snapshot' => MetricSnapshot::class,
        'dashboard' => Dashboard::class,
        'dashboard_kpi' => DashboardKpi::class,
        'report_template' => ReportTemplate::class,
        'report_schedule' => ReportSchedule::class,
        'report_run' => ReportRun::class,
        // Phase 3 slice 22 — Patient Portal (PRODUCT REQUIREMENTS §6.2,
        // DATABASE.md §3.53): portal accounts, sessions, and consent-bound
        // access grants. Payloads carry facts only — scopes, statuses,
        // timestamps, counts — never result values or clinical content.
        'portal_account' => PortalAccount::class,
        'portal_session' => PortalSession::class,
        'portal_access_grant' => PortalAccessGrant::class,
        // Phase 3 slice 23 — Interoperability readiness (DATABASE.md §3.42,
        // INTEROPERABILITY.md §10): the registry, its message log, the egress
        // allowlist, and the OAuth2 partner surface. Payloads carry facts
        // only — types, providers, statuses, scopes, timestamps — never PHI
        // (a projection audit records WHAT was projected, not its content).
        'integration' => Integration::class,
        'integration_event' => IntegrationEvent::class,
        'egress_destination' => EgressDestination::class,
        'oauth_partner' => OauthPartner::class,
        'oauth_partner_token' => OauthPartnerToken::class,
        // Phase 3 slice 24 — Telehealth (PRODUCT_REQUIREMENTS §6.20):
        // virtual consultations and their secure video sessions. Payloads
        // carry facts only — ids, statuses, mediums, timestamps — never
        // clinical content or PHI (a session audit records WHAT was opened,
        // not its media).
        'teleconsult' => Teleconsult::class,
        'video_session' => VideoSession::class,
        // Phase 3 slice 25 — RPM (ROADMAP Phase 20): devices, readings, and
        // alerts. Payloads carry facts and ids only — never reading values,
        // thresholds, or alert notes (those are clinical PHI).
        'rpm_device' => RpmDevice::class,
        'rpm_reading' => RpmReading::class,
        'rpm_alert' => RpmAlert::class,
        // Phase 21 — CDSS (ROADMAP Phase 21): the versioned knowledge base
        // and persisted check results. Payloads carry ids, codes, versions,
        // severities, and counts — never patient names or free-text alert
        // messages (those are clinical PHI on the check-result row, not in
        // the audit trail).
        'cdss_rule' => CdssRule::class,
        'cdss_check_result' => CdssCheckResult::class,
        // Phase 21 — Governed assistive AI (AI_RULES.md §11): registry
        // entries and drafts. Payloads carry ids, function, tier, model
        // id/version, and status — never draft text, prompts, or outputs
        // (OBSERVABILITY.md §17 never-log rules).
        'ai_feature' => AiFeature::class,
        'ai_draft' => AiDraft::class,
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

        // Patient-portal principals have no User row: the actor is the
        // portal account, audited as a patient identity with the login
        // identifier as the actor label (the same discipline as staff
        // emails — never the password, never PHI beyond the identifier).
        $portalAccount = $context->portalAccount;
        if ($actor === null && $portalAccount !== null) {
            $actorType = AuditEvent::ACTOR_PATIENT;
            $actorEmail ??= $portalAccount->login_identifier;
        }

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
            $supportSessionId, $portalAccount,
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
                'actor_id' => $actor?->getKey() ?? $portalAccount?->getKey(),
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
