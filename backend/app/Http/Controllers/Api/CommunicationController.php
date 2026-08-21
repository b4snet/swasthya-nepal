<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationTemplate;
use App\Models\Organization;
use App\Services\CommunicationService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hospital communication templates (Phase 81): CRUD for multi-channel
 * templates, preview with variable substitution, send dispatch, and
 * delivery status tracking.
 */
final class CommunicationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CommunicationService $comm,
    ) {}

    /**
     * GET /organizations/{org}/communication-templates — list templates.
     */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $query = CommunicationTemplate::query()
            ->where('tenant_id', $organization->getKey())
            ->orderByDesc('updated_at');

        if ($request->filled('category')) {
            $query->where('category', $request->validated('category'));
        }
        if ($request->filled('type')) {
            $query->where('type', $request->validated('type'));
        }

        $templates = $query->get()
            ->map(fn (CommunicationTemplate $t): array => $t->present())
            ->values();

        return Envelope::success(data: $templates, request: $request);
    }

    /**
     * GET /communication-templates/{template} — show template detail.
     */
    public function show(Request $request, CommunicationTemplate $template): JsonResponse
    {
        AccessCheck::scoped($template, write: false);

        return Envelope::success(data: $template->present(), request: $request);
    }

    /**
     * POST /organizations/{org}/communication-templates — create template.
     */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9_]{1,99}$/'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:appointment,followup,result,billing,discharge,portal,general'],
            'type' => ['required', 'string', 'in:confirmation,reminder,missed,invitation,notification,alert'],
            'channelInApp' => ['nullable', 'boolean'],
            'channelEmail' => ['nullable', 'boolean'],
            'channelSms' => ['nullable', 'boolean'],
            'channelWhatsapp' => ['nullable', 'boolean'],
            'subject' => ['nullable', 'string', 'max:255'],
            'bodyTemplate' => ['required', 'string', 'max:5000'],
            'whatsappMessage' => ['nullable', 'string', 'max:1000'],
            'smsMessage' => ['nullable', 'string', 'max:320'],
            'variables' => ['nullable', 'array'],
            'variables.*.name' => ['required_with:variables', 'string'],
            'variables.*.label' => ['required_with:variables', 'string'],
            'variables.*.type' => ['required_with:variables', 'string'],
            'variables.*.required' => ['required_with:variables', 'boolean'],
            'variables.*.example' => ['nullable', 'string'],
            'retryCount' => ['nullable', 'integer', 'min:0', 'max:10'],
            'retryDelayMinutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        $context = TenantContext::current();

        $template = CommunicationTemplate::query()->create([
            'tenant_id' => $organization->getKey(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'category' => $validated['category'],
            'type' => $validated['type'],
            'channel_in_app' => $validated['channelInApp'] ?? true,
            'channel_email' => $validated['channelEmail'] ?? false,
            'channel_sms' => $validated['channelSms'] ?? false,
            'channel_whatsapp' => $validated['channelWhatsapp'] ?? false,
            'subject' => $validated['subject'] ?? null,
            'body_template' => $validated['bodyTemplate'],
            'whatsapp_message' => $validated['whatsappMessage'] ?? null,
            'sms_message' => $validated['smsMessage'] ?? null,
            'variables' => $validated['variables'] ?? null,
            'retry_count' => $validated['retryCount'] ?? 0,
            'retry_delay_minutes' => $validated['retryDelayMinutes'] ?? 60,
            'locale' => $validated['locale'] ?? 'en',
        ]);

        $this->audit->record(
            'communication_template.created',
            'communication_templates',
            $template->getKey(),
            ['code' => $template->code, 'category' => $template->category, 'type' => $template->type],
            $request,
        );

        return Envelope::success(data: $template->present(), status: 201, request: $request);
    }

    /**
     * PUT /communication-templates/{template} — update template.
     */
    public function update(Request $request, CommunicationTemplate $template): JsonResponse
    {
        AccessCheck::scoped($template, write: true);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'in:appointment,followup,result,billing,discharge,portal,general'],
            'type' => ['sometimes', 'string', 'in:confirmation,reminder,missed,invitation,notification,alert'],
            'channelInApp' => ['nullable', 'boolean'],
            'channelEmail' => ['nullable', 'boolean'],
            'channelSms' => ['nullable', 'boolean'],
            'channelWhatsapp' => ['nullable', 'boolean'],
            'subject' => ['nullable', 'string', 'max:255'],
            'bodyTemplate' => ['sometimes', 'string', 'max:5000'],
            'whatsappMessage' => ['nullable', 'string', 'max:1000'],
            'smsMessage' => ['nullable', 'string', 'max:320'],
            'variables' => ['nullable', 'array'],
            'retryCount' => ['nullable', 'integer', 'min:0', 'max:10'],
            'retryDelayMinutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'enabled' => ['nullable', 'boolean'],
            'locale' => ['nullable', 'string', 'max:10'],
        ]);

        $mapping = [
            'name' => 'name', 'category' => 'category', 'type' => 'type',
            'subject' => 'subject', 'bodyTemplate' => 'body_template',
            'whatsappMessage' => 'whatsapp_message', 'smsMessage' => 'sms_message',
            'variables' => 'variables', 'locale' => 'locale', 'enabled' => 'enabled',
        ];

        $changes = [];
        foreach ($mapping as $input => $field) {
            if (array_key_exists($input, $validated)) {
                $changes[$field] = [$template->getAttribute($field), $validated[$input]];
                $template->setAttribute($field, $validated[$input]);
            }
        }

        // Boolean channel fields
        foreach (['channelInApp' => 'channel_in_app', 'channelEmail' => 'channel_email', 'channelSms' => 'channel_sms', 'channelWhatsapp' => 'channel_whatsapp'] as $input => $field) {
            if (array_key_exists($input, $validated)) {
                $changes[$field] = [$template->getAttribute($field), $validated[$input]];
                $template->setAttribute($field, $validated[$input]);
            }
        }

        if (array_key_exists('retryCount', $validated)) {
            $template->retry_count = $validated['retryCount'];
        }
        if (array_key_exists('retryDelayMinutes', $validated)) {
            $template->retry_delay_minutes = $validated['retryDelayMinutes'];
        }

        $template->save();

        $this->audit->record(
            'communication_template.updated',
            'communication_templates',
            $template->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: $template->fresh()->present(), request: $request);
    }

    /**
     * DELETE /communication-templates/{template} — soft delete.
     */
    public function destroy(Request $request, CommunicationTemplate $template): JsonResponse
    {
        AccessCheck::scoped($template, write: true);

        $template->delete();

        $this->audit->record(
            'communication_template.deleted',
            'communication_templates',
            $template->getKey(),
            ['code' => $template->code],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * POST /communication-templates/{template}/preview — render preview.
     */
    public function preview(Request $request, CommunicationTemplate $template): JsonResponse
    {
        AccessCheck::scoped($template, write: false);

        $validated = $request->validate([
            'variables' => ['nullable', 'array'],
        ]);

        // Use example values if no variables provided
        $variables = $validated['variables'] ?? [];
        if (empty($variables)) {
            $variables = $this->exampleVariables($template);
        }

        $rendered = $this->comm->preview($template, $variables);

        return Envelope::success(data: $rendered, request: $request);
    }

    /**
     * POST /communication-templates/{template}/send — dispatch communication.
     */
    public function send(Request $request, CommunicationTemplate $template): JsonResponse
    {
        AccessCheck::scoped($template, write: true);

        $validated = $request->validate([
            'variables' => ['required', 'array'],
            'patientId' => ['nullable', 'uuid'],
            'userId' => ['nullable', 'uuid'],
            'channel' => ['nullable', 'string', 'in:in_app,email,sms,whatsapp'],
        ]);

        $context = array_filter([
            'patientId' => $validated['patientId'] ?? null,
            'userId' => $validated['userId'] ?? null,
        ]);

        $result = $this->comm->send(
            $template,
            $validated['variables'],
            $context,
            $validated['channel'] ?? null,
        );

        return Envelope::success(data: $result, request: $request);
    }

    /**
     * GET /communication-templates/categories — list available categories and types.
     */
    public function categories(): JsonResponse
    {
        return Envelope::json([
            'categories' => CommunicationTemplate::categories(),
            'types' => CommunicationTemplate::types(),
        ]);
    }

    /**
     * GET /communication-templates/variable-presets — common variable presets.
     */
    public function variablePresets(): JsonResponse
    {
        return Envelope::json([
            'appointment' => [
                ['name' => 'patient_name', 'label' => 'Patient Name', 'type' => 'string', 'required' => true, 'example' => 'Ram Bahadur'],
                ['name' => 'doctor_name', 'label' => 'Doctor Name', 'type' => 'string', 'required' => true, 'example' => 'Dr. Sharma'],
                ['name' => 'date', 'label' => 'Appointment Date', 'type' => 'date', 'required' => true, 'example' => '2026-08-25'],
                ['name' => 'time', 'label' => 'Appointment Time', 'type' => 'time', 'required' => true, 'example' => '10:30 AM'],
                ['name' => 'hospital_name', 'label' => 'Hospital Name', 'type' => 'string', 'required' => true, 'example' => 'Swasthya Medical Center'],
                ['name' => 'department', 'label' => 'Department', 'type' => 'string', 'required' => false, 'example' => 'Cardiology'],
            ],
            'followup' => [
                ['name' => 'patient_name', 'label' => 'Patient Name', 'type' => 'string', 'required' => true, 'example' => 'Ram Bahadur'],
                ['name' => 'doctor_name', 'label' => 'Doctor Name', 'type' => 'string', 'required' => true, 'example' => 'Dr. Sharma'],
                ['name' => 'followup_date', 'label' => 'Follow-up Date', 'type' => 'date', 'required' => true, 'example' => '2026-09-01'],
                ['name' => 'reason', 'label' => 'Reason', 'type' => 'string', 'required' => false, 'example' => 'Post-surgery review'],
            ],
            'result' => [
                ['name' => 'patient_name', 'label' => 'Patient Name', 'type' => 'string', 'required' => true, 'example' => 'Ram Bahadur'],
                ['name' => 'result_type', 'label' => 'Result Type', 'type' => 'string', 'required' => true, 'example' => 'Laboratory'],
                ['name' => 'result_date', 'label' => 'Result Date', 'type' => 'date', 'required' => true, 'example' => '2026-08-21'],
                ['name' => 'portal_url', 'label' => 'Portal URL', 'type' => 'url', 'required' => false, 'example' => 'https://portal.swasthya.com'],
            ],
            'billing' => [
                ['name' => 'patient_name', 'label' => 'Patient Name', 'type' => 'string', 'required' => true, 'example' => 'Ram Bahadur'],
                ['name' => 'invoice_number', 'label' => 'Invoice Number', 'type' => 'string', 'required' => true, 'example' => 'INV-2026-001234'],
                ['name' => 'amount', 'label' => 'Amount', 'type' => 'currency', 'required' => true, 'example' => 'Rs. 2,500'],
                ['name' => 'due_date', 'label' => 'Due Date', 'type' => 'date', 'required' => true, 'example' => '2026-09-01'],
            ],
            'discharge' => [
                ['name' => 'patient_name', 'label' => 'Patient Name', 'type' => 'string', 'required' => true, 'example' => 'Ram Bahadur'],
                ['name' => 'discharge_date', 'label' => 'Discharge Date', 'type' => 'date', 'required' => true, 'example' => '2026-08-21'],
                ['name' => 'followup_date', 'label' => 'Follow-up Date', 'type' => 'date', 'required' => false, 'example' => '2026-09-04'],
                ['name' => 'doctor_name', 'label' => 'Doctor Name', 'type' => 'string', 'required' => true, 'example' => 'Dr. Sharma'],
            ],
            'portal' => [
                ['name' => 'patient_name', 'label' => 'Patient Name', 'type' => 'string', 'required' => true, 'example' => 'Ram Bahadur'],
                ['name' => 'portal_url', 'label' => 'Portal URL', 'type' => 'url', 'required' => true, 'example' => 'https://portal.swasthya.com'],
                ['name' => 'activation_code', 'label' => 'Activation Code', 'type' => 'string', 'required' => true, 'example' => 'ABC123'],
            ],
        ]);
    }

    /**
     * Generate example variables from a template's variable schema.
     *
     * @return array<string, string>
     */
    private function exampleVariables(CommunicationTemplate $template): array
    {
        $vars = [];
        foreach ($template->availableVariables() as $var) {
            $vars[$var['name']] = $var['example'] ?? 'Example';
        }

        return $vars;
    }
}
