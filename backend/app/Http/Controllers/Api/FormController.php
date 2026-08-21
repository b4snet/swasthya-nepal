<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentNumber;
use App\Models\FormSignature;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\FormTemplateCategory;
use App\Services\DocumentNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class FormController extends Controller
{
    public function __construct(
        private readonly DocumentNumberService $numberService,
    ) {}

    // ════════════════════════════════════════════════════════════════
    //  FORM TEMPLATES
    // ════════════════════════════════════════════════════════════════

    public function indexTemplates(Request $request): JsonResponse
    {
        $query = FormTemplate::query()
            ->where('tenant_id', $request->user()->currentTenantId());

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }
        if ($module = $request->query('module')) {
            $query->where('module', $module);
        }
        if ($request->boolean('active_only', true)) {
            $query->where('is_active', true);
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        $templates = $query->orderBy('category')->orderBy('name')->paginate(50);

        return response()->json($templates);
    }

    public function showTemplate(Request $request, string $id): JsonResponse
    {
        $template = FormTemplate::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        return response()->json($template);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'code' => 'required|string|max:50|unique:form_templates,code',
            'category' => 'required|string|in:'.implode(',', FormTemplate::CATEGORIES),
            'module' => 'nullable|string|in:'.implode(',', FormTemplate::MODULES),
            'specialty' => 'nullable|string|max:100',
            'workflow' => 'nullable|string|in:'.implode(',', FormTemplate::WORKFLOWS),
            'schema' => 'required|array',
            'layout' => 'nullable|array',
            'allowed_roles' => 'nullable|array',
            'description' => 'nullable|string',
            'department' => 'nullable|string|max:100',
            'printable' => 'boolean',
            'pdf_capable' => 'boolean',
            'linked_to_patient' => 'boolean',
            'linked_to_encounter' => 'boolean',
            'linked_to_admission' => 'boolean',
            'linked_to_appointment' => 'boolean',
            'generates_document_number' => 'boolean',
            'document_number_prefix' => 'nullable|string|max:10',
        ]);

        $validated['tenant_id'] = $request->user()->currentTenantId();
        $validated['facility_id'] = $request->user()->currentFacilityId();
        $validated['slug'] = \Str::slug($validated['name']);
        $validated['is_active'] = true;
        $validated['is_published'] = false;
        $validated['version'] = 1;

        $template = FormTemplate::create($validated);

        return response()->json($template, 201);
    }

    public function updateTemplate(Request $request, string $id): JsonResponse
    {
        $template = FormTemplate::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:200',
            'category' => 'sometimes|string|in:'.implode(',', FormTemplate::CATEGORIES),
            'module' => 'nullable|string|in:'.implode(',', FormTemplate::MODULES),
            'schema' => 'sometimes|array',
            'layout' => 'nullable|array',
            'allowed_roles' => 'nullable|array',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'is_published' => 'boolean',
        ]);

        $template->update($validated);

        return response()->json($template);
    }

    public function publishTemplate(Request $request, string $id): JsonResponse
    {
        $template = FormTemplate::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        $template->update([
            'is_published' => true,
            'version' => $template->version + 1,
        ]);

        return response()->json($template);
    }

    // ════════════════════════════════════════════════════════════════
    //  FORM SUBMISSIONS
    // ════════════════════════════════════════════════════════════════

    public function indexSubmissions(Request $request): JsonResponse
    {
        $query = FormSubmission::query()
            ->where('tenant_id', $request->user()->currentTenantId())
            ->with('template:id,name,code,category');

        if ($templateId = $request->query('template_id')) {
            $query->where('template_id', $templateId);
        }
        if ($patientId = $request->query('patient_id')) {
            $query->where('patient_id', $patientId);
        }
        if ($encounterId = $request->query('encounter_id')) {
            $query->where('encounter_id', $encounterId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $submissions = $query->orderByDesc('created_at')->paginate(50);

        return response()->json($submissions);
    }

    public function showSubmission(Request $request, string $id): JsonResponse
    {
        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->with(['template', 'signatures'])
            ->findOrFail($id);

        return response()->json($submission);
    }

    public function storeSubmission(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => 'required|uuid|exists:form_templates,id',
            'patient_id' => 'nullable|uuid',
            'encounter_id' => 'nullable|uuid',
            'admission_id' => 'nullable|uuid',
            'appointment_id' => 'nullable|uuid',
            'data' => 'required|array',
        ]);

        $template = FormTemplate::findOrFail($validated['template_id']);
        $userId = $request->user()->id;

        // Generate document number if template requires it
        $documentNumber = null;
        if ($template->generates_document_number) {
            $documentNumber = $this->numberService->next(
                $request->user()->currentTenantId(),
                $template->document_number_prefix ?? 'form',
                $request->user()->currentFacilityId(),
            );
        }

        $submission = FormSubmission::create([
            'tenant_id' => $request->user()->currentTenantId(),
            'facility_id' => $request->user()->currentFacilityId(),
            'template_id' => $validated['template_id'],
            'template_version' => $template->version,
            'patient_id' => $validated['patient_id'] ?? null,
            'encounter_id' => $validated['encounter_id'] ?? null,
            'admission_id' => $validated['admission_id'] ?? null,
            'appointment_id' => $validated['appointment_id'] ?? null,
            'data' => $validated['data'],
            'document_number' => $documentNumber,
            'status' => FormSubmission::STATUS_DRAFT,
            'submitted_by' => $userId,
            'submitted_by_type' => 'staff',
        ]);

        return response()->json($submission, 201);
    }

    public function submitForm(Request $request, string $id): JsonResponse
    {
        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        if ($submission->status !== FormSubmission::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => 'Only draft submissions can be submitted.',
            ]);
        }

        $submission->markSubmitted();

        return response()->json($submission);
    }

    public function verifySubmission(Request $request, string $id): JsonResponse
    {
        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        if ($submission->status !== FormSubmission::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => 'Only submitted forms can be verified.',
            ]);
        }

        $submission->markVerified($request->user()->id);

        return response()->json($submission);
    }

    public function approveSubmission(Request $request, string $id): JsonResponse
    {
        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        if ($submission->status !== FormSubmission::STATUS_VERIFIED) {
            throw ValidationException::withMessages([
                'status' => 'Only verified forms can be approved.',
            ]);
        }

        $submission->markApproved($request->user()->id);

        return response()->json($submission);
    }

    public function cancelSubmission(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        if (in_array($submission->status, [FormSubmission::STATUS_CANCELLED, FormSubmission::STATUS_APPROVED])) {
            throw ValidationException::withMessages([
                'status' => 'Cannot cancel this submission.',
            ]);
        }

        $submission->markCancelled($request->user()->id, $validated['reason']);

        return response()->json($submission);
    }

    public function recordPrint(Request $request, string $id): JsonResponse
    {
        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($id);

        $submission->recordPrint($request->user()->id);

        return response()->json(['print_count' => $submission->print_count]);
    }

    // ════════════════════════════════════════════════════════════════
    //  SIGNATURES
    // ════════════════════════════════════════════════════════════════

    public function addSignature(Request $request, string $submissionId): JsonResponse
    {
        $validated = $request->validate([
            'signature_type' => 'required|string|in:'.implode(',', FormSignature::TYPES),
            'signer_name' => 'required|string|max:200',
            'signer_role' => 'nullable|string|max:100',
            'signature_data' => 'required|string',
            'signature_method' => 'string|in:drawn,typed,uploaded,digital',
        ]);

        $submission = FormSubmission::where('tenant_id', $request->user()->currentTenantId())
            ->findOrFail($submissionId);

        $signature = FormSignature::create([
            'tenant_id' => $request->user()->currentTenantId(),
            'submission_id' => $submissionId,
            'signature_type' => $validated['signature_type'],
            'signer_id' => $request->user()->id,
            'signer_name' => $validated['signer_name'],
            'signer_role' => $validated['signer_role'] ?? null,
            'signature_data' => $validated['signature_data'],
            'signature_method' => $validated['signature_method'] ?? 'drawn',
            'signed_at' => now(),
            'ip_address' => $request->ip(),
        ]);

        return response()->json($signature, 201);
    }

    public function listSignatures(Request $request, string $submissionId): JsonResponse
    {
        $signatures = FormSignature::where('tenant_id', $request->user()->currentTenantId())
            ->where('submission_id', $submissionId)
            ->get();

        return response()->json($signatures);
    }

    // ════════════════════════════════════════════════════════════════
    //  DOCUMENT NUMBERING
    // ════════════════════════════════════════════════════════════════

    public function generateNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => 'required|string|in:'.implode(',', array_keys(DocumentNumber::PREFIXES)),
        ]);

        $number = $this->numberService->next(
            $request->user()->currentTenantId(),
            $validated['document_type'],
            $request->user()->currentFacilityId(),
        );

        return response()->json(['document_number' => $number]);
    }

    // ════════════════════════════════════════════════════════════════
    //  FORM CATEGORIES
    // ════════════════════════════════════════════════════════════════

    public function indexCategories(Request $request): JsonResponse
    {
        $categories = FormTemplateCategory::where('tenant_id', $request->user()->currentTenantId())
            ->active()
            ->ordered()
            ->get();

        return response()->json($categories);
    }
}
