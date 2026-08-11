<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Audit trail reads (API_CONTRACTS.md §16, TENANCY.md §11).
 *
 * Tenant-scoped: a tenant sees its own events; platform principals see
 * platform events (tenant_id NULL) — never another tenant's trail (blanket
 * cross-tenant audit access is a per-grant support operation, TENANCY.md
 * §10). There is no endpoint that can edit or delete audit events; such a
 * path does not exist (MASTER_RULES.md §19.5).
 */
final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();
        $limit = min((int) $request->input('limit', 100), 200);

        $query = AuditEvent::query()->latest();

        if ($context->isPlatform) {
            $query->whereNull('tenant_id');
        } else {
            $query->where('tenant_id', $context->tenantId());
        }

        $events = $query->limit($limit)->get();

        return Envelope::success(
            data: $events->map(fn (AuditEvent $event): array => [
                'id' => $event->getKey(),
                'action' => $event->action,
                'resourceType' => $event->resource_type,
                'resourceId' => $event->resource_id,
                'facilityId' => $event->facility_id,
                'actorId' => $event->actor_id,
                'actorEmail' => $event->actor_email,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
                'payload' => $event->payload,
                'correlationId' => $event->correlation_id,
            ])->values(),
            meta: ['total' => $events->count()],
            request: $request,
        );
    }
}
