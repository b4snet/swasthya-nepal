<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NumberingConfig;
use App\Services\DocumentNumberService;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NumberingController extends Controller
{
    public function __construct(
        private readonly DocumentNumberService $numberService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * List all numbering configurations for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = TenantContext::current()->tenantId;

        $configs = NumberingConfig::where('tenant_id', $tenantId)
            ->orderBy('document_type')
            ->get();

        return Envelope::success(data: $configs, request: $request);
    }

    /**
     * Get a specific numbering configuration.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $config = NumberingConfig::where('tenant_id', TenantContext::current()->tenantId)
            ->findOrFail($id);

        return Envelope::success(data: $config, request: $request);
    }

    /**
     * Create or update a numbering configuration.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document_type' => ['required', 'string', 'max:50', Rule::in(array_keys(NumberingConfig::DOCUMENT_TYPES))],
            'prefix' => ['required', 'string', 'max:20'],
            'sequence_length' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'date_format' => ['nullable', 'string', 'max:20'],
            'reset_policy' => ['sometimes', 'string', Rule::in(NumberingConfig::RESET_POLICIES)],
            'include_facility' => ['sometimes', 'boolean'],
            'separator' => ['sometimes', 'string', 'max:5'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $tenantId = TenantContext::current()->tenantId;

        $config = NumberingConfig::updateOrCreate(
            ['tenant_id' => $tenantId, 'document_type' => $validated['document_type']],
            $validated,
        );

        $this->audit->record(
            $config->wasRecentlyCreated ? 'numbering.created' : 'numbering.updated',
            'numbering_config',
            $config->getKey(),
            ['document_type' => $config->document_type, 'prefix' => $config->prefix],
            $request,
        );

        return Envelope::success(
            data: $config,
            status: $config->wasRecentlyCreated ? 201 : 200,
            request: $request,
        );
    }

    /**
     * Update an existing numbering configuration.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $config = NumberingConfig::where('tenant_id', TenantContext::current()->tenantId)
            ->findOrFail($id);

        $validated = $request->validate([
            'prefix' => ['sometimes', 'string', 'max:20'],
            'sequence_length' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'date_format' => ['nullable', 'string', 'max:20'],
            'reset_policy' => ['sometimes', 'string', Rule::in(NumberingConfig::RESET_POLICIES)],
            'include_facility' => ['sometimes', 'boolean'],
            'separator' => ['sometimes', 'string', 'max:5'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $config->update($validated);

        $this->audit->record(
            'numbering.updated',
            'numbering_config',
            $config->getKey(),
            ['changes' => $validated],
            $request,
        );

        return Envelope::success(data: $config, request: $request);
    }

    /**
     * Preview the next number for a configuration.
     */
    public function preview(Request $request, string $id): JsonResponse
    {
        $config = NumberingConfig::where('tenant_id', TenantContext::current()->tenantId)
            ->findOrFail($id);

        return Envelope::success(data: ['preview' => $config->preview()], request: $request);
    }

    /**
     * Generate a number using the configuration.
     */
    public function generate(Request $request, string $id): JsonResponse
    {
        $config = NumberingConfig::where('tenant_id', TenantContext::current()->tenantId)
            ->where('is_active', true)
            ->findOrFail($id);

        $number = $this->numberService->nextFromConfig($config, $request->input('facilityId'));

        return Envelope::success(data: ['document_number' => $number], request: $request);
    }

    /**
     * List all available document types.
     */
    public function types(Request $request): JsonResponse
    {
        return Envelope::success(
            data: collect(NumberingConfig::DOCUMENT_TYPES)->map(fn ($label, $key) => [
                'type' => $key,
                'label' => $label,
            ])->values(),
            request: $request,
        );
    }
}
