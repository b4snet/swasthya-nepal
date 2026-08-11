<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StorePayerRequest;
use App\Models\Organization;
use App\Models\Payer;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The tenant's payers master (DATABASE.md §3.45): insurers, TPAs, and
 * government schemes. Tenant-wide — a policy covers a patient at any
 * facility of the tenant.
 */
final class PayerController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $payers = Payer::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name')
            ->get()
            ->map(fn (Payer $payer): array => [
                'id' => $payer->getKey(),
                'name' => $payer->name,
                'code' => $payer->code,
                'payerType' => $payer->payer_type,
                'status' => $payer->status,
            ])
            ->values();

        return Envelope::success(data: $payers, request: $request);
    }

    public function store(StorePayerRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();

        $payer = Payer::query()->create([
            'tenant_id' => $organization->getKey(),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'payer_type' => $request->validated('payerType'),
            'status' => Payer::STATUS_ACTIVE,
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'payer.created',
            'payer',
            $payer->getKey(),
            ['code' => $payer->code, 'name' => $payer->name, 'payerType' => $payer->payer_type],
            $request,
        );

        return Envelope::success(
            data: [
                'id' => $payer->getKey(),
                'name' => $payer->name,
                'code' => $payer->code,
                'payerType' => $payer->payer_type,
                'status' => $payer->status,
            ],
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/payers/'.$payer->getKey(),
            ],
        );
    }
}
