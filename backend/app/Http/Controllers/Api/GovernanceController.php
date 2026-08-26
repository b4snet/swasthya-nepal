<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CorrectiveAction;
use App\Models\DisclosureLog;
use App\Models\HospitalIncident;
use App\Models\HospitalPolicy;
use App\Models\PatientComplaint;
use App\Models\StaffCredential;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class GovernanceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function listPolicies(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = HospitalPolicy::where('tenant_id', $ctx->tenantId());
        if ($facility = $request->query('facility_id')) {
            $query->where('facility_id', $facility);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return Envelope::success(data: $query->orderByDesc('created_at')->paginate(25), request: $request);
    }

    public function showPolicy(Request $request, HospitalPolicy $policy): JsonResponse
    {
        AccessCheck::scoped($policy, write: false);

        return Envelope::success(data: $policy, request: $request);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255', 'category' => 'required|string|max:100',
            'content' => 'nullable|array', 'effective_date' => 'nullable|date',
            'review_date' => 'nullable|date', 'facility_id' => 'nullable|uuid',
        ]);
        $ctx = TenantContext::current();
        $policy = HospitalPolicy::create([
            'tenant_id' => $ctx->tenantId(), 'facility_id' => $data['facility_id'] ?? $ctx->facilityId(),
            'policy_code' => 'POL-'.strtoupper(Str::random(8)), 'title' => $data['title'],
            'category' => $data['category'], 'content' => $data['content'] ?? null,
            'effective_date' => $data['effective_date'] ?? null, 'review_date' => $data['review_date'] ?? null,
            'status' => 'draft', 'owner_staff_id' => $ctx->user?->getKey(),
        ]);
        $this->audit->record('policy.created', 'policy', $policy->getKey(), ['policyCode' => $policy->policy_code], $request);

        return Envelope::success(data: $policy, status: 201, request: $request);
    }

    public function updatePolicy(Request $request, HospitalPolicy $policy): JsonResponse
    {
        AccessCheck::scoped($policy, write: true);
        $data = $request->validate([
            'title' => 'sometimes|string|max:255', 'category' => 'sometimes|string|max:100',
            'content' => 'nullable|array', 'status' => 'sometimes|string|in:draft,review,approved,published,superseded,retired',
            'effective_date' => 'nullable|date', 'review_date' => 'nullable|date',
        ]);
        $policy->update($data);
        $this->audit->record('policy.updated', 'policy', $policy->getKey(), ['changes' => array_keys($data)], $request);

        return Envelope::success(data: $policy, request: $request);
    }

    public function destroyPolicy(Request $request, HospitalPolicy $policy): JsonResponse
    {
        AccessCheck::scoped($policy, write: true);
        $policy->update(['status' => 'retired']);
        $this->audit->record('policy.retired', 'policy', $policy->getKey(), [], $request);

        return Envelope::success(data: ['id' => $policy->getKey(), 'status' => $policy->status], request: $request);
    }

    public function listIncidents(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = HospitalIncident::where('tenant_id', $ctx->tenantId());
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($severity = $request->query('severity')) {
            $query->where('severity', $severity);
        }
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        return Envelope::success(data: $query->withCount('correctiveActions')->orderByDesc('reported_at')->paginate(25), request: $request);
    }

    public function showIncident(Request $request, HospitalIncident $incident): JsonResponse
    {
        AccessCheck::scoped($incident, write: false);
        $incident->load('correctiveActions');

        return Envelope::success(data: $incident, request: $request);
    }

    public function storeIncident(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255', 'category' => 'required|string|max:100',
            'severity' => 'required|string|in:critical,high,medium,low',
            'description' => 'nullable|array', 'patient_id' => 'nullable|uuid', 'encounter_id' => 'nullable|uuid',
        ]);
        $ctx = TenantContext::current();
        $incident = HospitalIncident::create([
            'tenant_id' => $ctx->tenantId(), 'facility_id' => $ctx->facilityId(),
            'incident_code' => 'INC-'.strtoupper(Str::random(8)), 'title' => $data['title'],
            'category' => $data['category'], 'severity' => $data['severity'],
            'description' => $data['description'] ?? null, 'reported_by' => $ctx->user?->getKey(),
            'reported_at' => now(), 'status' => 'reported',
            'patient_id' => $data['patient_id'] ?? null, 'encounter_id' => $data['encounter_id'] ?? null,
        ]);
        $this->audit->record('incident.reported', 'incident', $incident->getKey(), ['incidentCode' => $incident->incident_code, 'severity' => $incident->severity], $request);

        return Envelope::success(data: $incident, status: 201, request: $request);
    }

    public function updateIncident(Request $request, HospitalIncident $incident): JsonResponse
    {
        AccessCheck::scoped($incident, write: true);
        $data = $request->validate([
            'status' => 'sometimes|string|in:reported,reviewing,investigating,actions_pending,closed',
            'severity' => 'sometimes|string|in:critical,high,medium,low',
            'assigned_to' => 'nullable|uuid', 'root_cause' => 'nullable|string',
            'contributing_factors' => 'nullable|array',
        ]);
        $incident->update($data);
        $this->audit->record('incident.updated', 'incident', $incident->getKey(), ['changes' => array_keys($data)], $request);

        return Envelope::success(data: $incident, request: $request);
    }

    public function listActions(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = CorrectiveAction::where('tenant_id', $ctx->tenantId());
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($incidentId = $request->query('incident_id')) {
            $query->where('incident_id', $incidentId);
        }

        return Envelope::success(data: $query->orderByDesc('created_at')->paginate(25), request: $request);
    }

    public function storeAction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255', 'description' => 'nullable|string',
            'action_type' => 'required|string|in:corrective,preventive',
            'incident_id' => 'nullable|uuid', 'compliance_report_id' => 'nullable|uuid',
            'owner_staff_id' => 'nullable|uuid', 'due_date' => 'nullable|date',
        ]);
        $ctx = TenantContext::current();
        $action = CorrectiveAction::create([
            'tenant_id' => $ctx->tenantId(), 'facility_id' => $ctx->facilityId(),
            'action_code' => 'CA-'.strtoupper(Str::random(8)), 'title' => $data['title'],
            'description' => $data['description'] ?? null, 'action_type' => $data['action_type'],
            'incident_id' => $data['incident_id'] ?? null, 'compliance_report_id' => $data['compliance_report_id'] ?? null,
            'owner_staff_id' => $data['owner_staff_id'] ?? null, 'due_date' => $data['due_date'] ?? null,
            'status' => 'open',
        ]);
        $this->audit->record('action.created', 'corrective_action', $action->getKey(), ['actionCode' => $action->action_code], $request);

        return Envelope::success(data: $action, status: 201, request: $request);
    }

    public function updateAction(Request $request, CorrectiveAction $action): JsonResponse
    {
        AccessCheck::scoped($action, write: true);
        $data = $request->validate([
            'status' => 'sometimes|string|in:open,in_progress,verified,closed',
            'completed_date' => 'nullable|date', 'verified_by' => 'nullable|uuid', 'evidence' => 'nullable|array',
        ]);
        if (isset($data['verified_by'])) {
            $data['verified_at'] = now();
        }
        $action->update($data);
        $this->audit->record('action.updated', 'corrective_action', $action->getKey(), ['changes' => array_keys($data)], $request);

        return Envelope::success(data: $action, request: $request);
    }

    public function listCredentials(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = StaffCredential::where('tenant_id', $ctx->tenantId());
        if ($staffId = $request->query('staff_id')) {
            $query->where('staff_id', $staffId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return Envelope::success(data: $query->orderByDesc('created_at')->paginate(25), request: $request);
    }

    public function storeCredential(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_id' => 'required|uuid', 'credential_type' => 'required|string|max:100',
            'title' => 'required|string|max:255', 'credential_code' => 'nullable|string',
            'issuing_authority' => 'nullable|string', 'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date', 'document_id' => 'nullable|uuid',
        ]);
        $ctx = TenantContext::current();
        $cred = StaffCredential::create([
            'tenant_id' => $ctx->tenantId(), 'facility_id' => $ctx->facilityId(),
            'staff_id' => $data['staff_id'], 'credential_type' => $data['credential_type'],
            'title' => $data['title'], 'credential_code' => $data['credential_code'] ?? null,
            'issuing_authority' => $data['issuing_authority'] ?? null,
            'issue_date' => $data['issue_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null,
            'document_id' => $data['document_id'] ?? null, 'status' => 'active',
        ]);
        $this->audit->record('credential.created', 'staff_credential', $cred->getKey(), ['staffId' => $cred->staff_id], $request);

        return Envelope::success(data: $cred, status: 201, request: $request);
    }

    public function listComplaints(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();
        $query = PatientComplaint::where('tenant_id', $ctx->tenantId());
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return Envelope::success(data: $query->orderByDesc('created_at')->paginate(25), request: $request);
    }

    public function storeComplaint(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => 'nullable|uuid', 'category' => 'required|string|max:100',
            'title' => 'required|string|max:255', 'description' => 'nullable|array',
            'severity' => 'sometimes|string|in:critical,high,medium,low',
        ]);
        $ctx = TenantContext::current();
        $complaint = PatientComplaint::create([
            'tenant_id' => $ctx->tenantId(), 'facility_id' => $ctx->facilityId(),
            'complaint_code' => 'CMP-'.strtoupper(Str::random(8)), 'patient_id' => $data['patient_id'] ?? null,
            'category' => $data['category'], 'title' => $data['title'],
            'description' => $data['description'] ?? null, 'severity' => $data['severity'] ?? 'medium',
            'status' => 'submitted',
        ]);
        $this->audit->record('complaint.submitted', 'patient_complaint', $complaint->getKey(), ['complaintCode' => $complaint->complaint_code], $request);

        return Envelope::success(data: $complaint, status: 201, request: $request);
    }

    public function updateComplaint(Request $request, PatientComplaint $complaint): JsonResponse
    {
        AccessCheck::scoped($complaint, write: true);
        $data = $request->validate([
            'status' => 'sometimes|string|in:submitted,triaged,assigned,investigating,responded,closed',
            'assigned_to' => 'nullable|uuid', 'response' => 'nullable|array', 'responded_by' => 'nullable|uuid',
        ]);
        if (isset($data['status']) && $data['status'] === 'closed') {
            $data['closed_at'] = now();
        }
        if (isset($data['response'])) {
            $data['responded_at'] = now();
        }
        $complaint->update($data);
        $this->audit->record('complaint.updated', 'patient_complaint', $complaint->getKey(), ['changes' => array_keys($data)], $request);

        return Envelope::success(data: $complaint, request: $request);
    }

    public function listDisclosures(Request $request): JsonResponse
    {
        $ctx = TenantContext::current();

        return Envelope::success(data: DisclosureLog::where('tenant_id', $ctx->tenantId())->orderByDesc('created_at')->paginate(25), request: $request);
    }

    public function storeDisclosure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'patient_id' => 'nullable|uuid', 'requester_name' => 'required|string|max:255',
            'requester_organization' => 'nullable|string', 'purpose' => 'required|string',
            'recipient_name' => 'nullable|string', 'recipient_organization' => 'nullable|string',
            'documents' => 'nullable|array',
        ]);
        $ctx = TenantContext::current();
        $log = DisclosureLog::create([
            'tenant_id' => $ctx->tenantId(), 'facility_id' => $ctx->facilityId(),
            'patient_id' => $data['patient_id'] ?? null, 'requester_name' => $data['requester_name'],
            'requester_organization' => $data['requester_organization'] ?? null, 'purpose' => $data['purpose'],
            'recipient_name' => $data['recipient_name'] ?? null, 'recipient_organization' => $data['recipient_organization'] ?? null,
            'documents' => $data['documents'] ?? null, 'status' => 'requested',
        ]);
        $this->audit->record('disclosure.requested', 'disclosure_log', $log->getKey(), ['requester' => $log->requester_name], $request);

        return Envelope::success(data: $log, status: 201, request: $request);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $tid = TenantContext::current()->tenantId();
        $data = [
            'openIncidents' => HospitalIncident::where('tenant_id', $tid)->where('status', '!=', 'closed')->count(),
            'criticalIncidents' => HospitalIncident::where('tenant_id', $tid)->where('severity', 'critical')->where('status', '!=', 'closed')->count(),
            'overdueActions' => CorrectiveAction::where('tenant_id', $tid)->whereNotIn('status', ['closed', 'verified'])->where('due_date', '<', now())->count(),
            'openComplaints' => PatientComplaint::where('tenant_id', $tid)->where('status', '!=', 'closed')->count(),
            'activePolicies' => HospitalPolicy::where('tenant_id', $tid)->where('status', 'published')->count(),
            'expiringCredentials' => StaffCredential::where('tenant_id', $tid)->where('status', 'active')->where('expiry_date', '<=', now()->addDays(30))->count(),
        ];

        return Envelope::success(data: $data, request: $request);
    }
}
