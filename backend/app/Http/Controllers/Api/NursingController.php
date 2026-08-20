<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarePlan;
use App\Models\NursingAlert;
use App\Models\NursingTask;
use App\Models\ShiftHandover;
use App\Models\VitalObservation;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NursingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function tasks(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $tasks = NursingTask::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('due_at')
            ->get();

        return Envelope::success(data: $tasks, request: $request);
    }

    public function storeTask(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $request->validate([
            'patientId' => 'required|uuid',
            'taskType' => 'required|string|max:50',
            'description' => 'required|string|max:2000',
            'priority' => 'sometimes|in:routine,urgent,stat',
            'assignedTo' => 'nullable|uuid',
            'dueAt' => 'nullable|date',
            'admissionId' => 'nullable|uuid',
        ]);
        $task = NursingTask::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'patient_id' => $request->input('patientId'),
            'admission_id' => $request->input('admissionId'),
            'assigned_to' => $request->input('assignedTo'),
            'task_type' => $request->input('taskType'),
            'description' => $request->input('description'),
            'priority' => $request->input('priority', 'routine'),
            'status' => 'pending',
            'due_at' => $request->input('dueAt'),
        ]);
        $this->audit->record('nursing_task.created', 'nursing_task', $task->getKey(), ['taskType' => $task->task_type], $request);

        return Envelope::success(data: $task, status: 201, request: $request);
    }

    public function completeTask(Request $request, NursingTask $nursingTask): JsonResponse
    {
        $request->validate(['completionNotes' => 'nullable|string|max:2000']);
        $nursingTask->update([
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $request->input('completedBy'),
            'completion_notes' => $request->input('completionNotes'),
        ]);
        $this->audit->record('nursing_task.completed', 'nursing_task', $nursingTask->getKey(), [], $request);

        return Envelope::success(data: $nursingTask->fresh(), request: $request);
    }

    public function vitals(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $vitals = VitalObservation::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('observed_at')
            ->limit(100)
            ->get();

        return Envelope::success(data: $vitals, request: $request);
    }

    public function storeVital(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $request->validate([
            'patientId' => 'required|uuid',
            'recordedBy' => 'required|uuid',
            'observedAt' => 'required|date',
            'temperatureCelsius' => 'nullable|numeric|min:30|max:45',
            'heartRateBpm' => 'nullable|integer|min:20|max:300',
            'respiratoryRate' => 'nullable|integer|min:5|max:80',
            'systolicBp' => 'nullable|integer|min:50|max:300',
            'diastolicBp' => 'nullable|integer|min:20|max:200',
            'spo2Percent' => 'nullable|numeric|min:0|max:100',
            'painScore' => 'nullable|integer|min:0|max:10',
            'gcsScore' => 'nullable|integer|min:3|max:15',
            'notes' => 'nullable|string|max:2000',
            'admissionId' => 'nullable|uuid',
        ]);
        $vital = VitalObservation::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'patient_id' => $request->input('patientId'),
            'admission_id' => $request->input('admissionId'),
            'recorded_by' => $request->input('recordedBy'),
            'temperature_celsius' => $request->input('temperatureCelsius'),
            'heart_rate_bpm' => $request->input('heartRateBpm'),
            'respiratory_rate' => $request->input('respiratoryRate'),
            'systolic_bp' => $request->input('systolicBp'),
            'diastolic_bp' => $request->input('diastolicBp'),
            'spo2_percent' => $request->input('spo2Percent'),
            'pain_score' => $request->input('painScore'),
            'gcs_score' => $request->input('gcsScore'),
            'notes' => $request->input('notes'),
            'observed_at' => $request->input('observedAt'),
        ]);
        $this->audit->record('vital_observation.recorded', 'vital_observation', $vital->getKey(), [], $request);

        return Envelope::success(data: $vital, status: 201, request: $request);
    }

    public function carePlans(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $plans = CarePlan::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('created_at')
            ->get();

        return Envelope::success(data: $plans, request: $request);
    }

    public function storeCarePlan(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $request->validate([
            'patientId' => 'required|uuid',
            'createdBy' => 'required|uuid',
            'diagnosis' => 'required|string|max:255',
            'goals' => 'required|string|max:4000',
            'interventions' => 'required|string|max:4000',
            'effectiveFrom' => 'required|date',
            'effectiveUntil' => 'nullable|date',
            'admissionId' => 'nullable|uuid',
        ]);
        $plan = CarePlan::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'patient_id' => $request->input('patientId'),
            'admission_id' => $request->input('admissionId'),
            'created_by' => $request->input('createdBy'),
            'diagnosis' => $request->input('diagnosis'),
            'goals' => $request->input('goals'),
            'interventions' => $request->input('interventions'),
            'status' => 'active',
            'effective_from' => $request->input('effectiveFrom'),
            'effective_until' => $request->input('effectiveUntil'),
        ]);
        $this->audit->record('care_plan.created', 'care_plan', $plan->getKey(), [], $request);

        return Envelope::success(data: $plan, status: 201, request: $request);
    }

    public function handovers(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $handovers = ShiftHandover::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('handover_date')
            ->get();

        return Envelope::success(data: $handovers, request: $request);
    }

    public function storeHandover(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $request->validate([
            'outgoingStaffId' => 'required|uuid',
            'incomingStaffId' => 'required|uuid',
            'shift' => 'required|string|max:20',
            'handoverDate' => 'required|date',
            'patientSummaries' => 'required|string|max:10000',
            'criticalItems' => 'nullable|string|max:5000',
            'pendingTasks' => 'nullable|string|max:5000',
        ]);
        $handover = ShiftHandover::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'outgoing_staff_id' => $request->input('outgoingStaffId'),
            'incoming_staff_id' => $request->input('incomingStaffId'),
            'shift' => $request->input('shift'),
            'handover_date' => $request->input('handoverDate'),
            'patient_summaries' => $request->input('patientSummaries'),
            'critical_items' => $request->input('criticalItems'),
            'pending_tasks' => $request->input('pendingTasks'),
        ]);
        $this->audit->record('shift_handover.created', 'shift_handover', $handover->getKey(), [], $request);

        return Envelope::success(data: $handover, status: 201, request: $request);
    }

    public function acceptHandover(Request $request, ShiftHandover $shiftHandover): JsonResponse
    {
        $shiftHandover->update([
            'status' => 'accepted',
            'accepted_by' => $request->input('acceptedBy'),
            'accepted_at' => now(),
        ]);
        $this->audit->record('shift_handover.accepted', 'shift_handover', $shiftHandover->getKey(), [], $request);

        return Envelope::success(data: $shiftHandover->fresh(), request: $request);
    }

    public function alerts(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $alerts = NursingAlert::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return Envelope::success(data: $alerts, request: $request);
    }

    public function storeAlert(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $request->validate([
            'patientId' => 'required|uuid',
            'alertTo' => 'required|uuid',
            'alertType' => 'required|string|max:50',
            'severity' => 'sometimes|in:info,warning,critical',
            'message' => 'required|string|max:2000',
        ]);
        $alert = NursingAlert::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'patient_id' => $request->input('patientId'),
            'alert_to' => $request->input('alertTo'),
            'alert_type' => $request->input('alertType'),
            'severity' => $request->input('severity', 'info'),
            'message' => $request->input('message'),
        ]);

        return Envelope::success(data: $alert, status: 201, request: $request);
    }

    public function acknowledgeAlert(Request $request, NursingAlert $nursingAlert): JsonResponse
    {
        $nursingAlert->update([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'acknowledged_by' => $request->input('acknowledgedBy'),
        ]);

        return Envelope::success(data: $nursingAlert->fresh(), request: $request);
    }
}
