<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ProvisionOrganizationRequest;
use App\Http\Requests\Organization\StoreOrganizationRequest;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\DatabaseTenantContext;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Organizations (API_CONTRACTS.md §21.2, TENANCY.md §12).
 *
 *  - index: the caller's own organizations, derived from active assignments.
 *  - show: single org, membership-checked (404 for non-members — existence
 *    is never leaked).
 *  - store: tenant provisioning — a platform operation (organization:manage)
 *    that creates the org row; the full idempotent provisioning flow
 *    (facility + bootstrap admin + verification) is the TENANCY.md §12
 *    runbook, documented as follow-up.
 */
final class OrganizationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $organizations = $context->assignments
            ->pluck('organization')
            ->filter()
            ->unique('id')
            ->map(fn (Organization $organization): array => [
                'id' => $organization->getKey(),
                'code' => $organization->code,
                'name' => $organization->name,
                'status' => $organization->status,
                'facilities' => $organization->facilities()
                    ->where('status', 'active')
                    ->get(['id', 'name', 'code'])
                    ->toArray(),
            ])
            ->values();

        return Envelope::success(data: $organizations, request: $request);
    }

    public function show(Request $request, string $organization): JsonResponse
    {
        $org = AccessCheck::organization($organization, write: false);

        return Envelope::success(
            data: [
                'id' => $org->getKey(),
                'code' => $org->code,
                'name' => $org->name,
                'status' => $org->status,
                'currency' => $org->currency,
                'timezone' => $org->timezone,
                'locale' => $org->locale,
                'settings' => $org->settings,
            ],
            request: $request,
        );
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $organization = Organization::query()->create([
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'currency' => $request->validated('currency'),
            'timezone' => $request->validated('timezone'),
            'locale' => 'en',
            'tax_config' => [],
            'settings' => [],
            'created_by' => $context->user?->getKey(),
        ]);

        // Platform event — no tenant context on the row.
        $event = $this->audit->record(
            'organization.created',
            'organization',
            $organization->getKey(),
            ['code' => $organization->code],
            $request,
        );

        return Envelope::success(
            data: $this->payload($organization),
            meta: [],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey(), 'Location' => '/api/v1/organizations/'.$organization->getKey()],
        );
    }

    /**
     * Tenant bootstrap (TENANCY.md V2 §8, §12): after a platform admin
     * creates the organization row, provisioning creates the tenant's first
     * facility, its first administrator identity, and the org_admin
     * assignment — all inside an explicit server-side tenant context and
     * recorded as one audited 'organization.provisioned' event.
     *
     * Provisioning is idempotent by construction: an organization with an
     * existing facility is already provisioned (409) — the first facility is
     * the marker. Subsequent facilities are created by the tenant's own
     * administrators through the normal (tenant-context) APIs.
     */
    public function provision(ProvisionOrganizationRequest $request, string $organization): JsonResponse
    {
        $context = TenantContext::current();
        $org = AccessCheck::organization($organization, write: true);

        if ($org->status !== Organization::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::TENANT_SUSPENDED,
                'This organization is not active. Contact your administrator.',
                403,
            );
        }

        if ($org->facilities()->exists()) {
            throw new ApiException(
                ErrorCodes::RESOURCE_EXISTS,
                'This organization is already provisioned.',
                409,
            );
        }

        $adminRole = Role::query()->where('code', 'org_admin')->firstOrFail();

        $facility = DB::transaction(function () use ($request, $org, $context, $adminRole): Facility {
            // The provisioning writes are explicitly scoped to the target
            // tenant (server-derived, never client-chosen) — the audited
            // exception to the empty-tenant platform context.
            DatabaseTenantContext::setTenant($org->getKey());

            $facility = Facility::query()->create([
                'tenant_id' => $org->getKey(),
                'name' => $request->validated('facilityName'),
                'code' => $request->validated('facilityCode'),
                'timezone' => $org->timezone,
                'status' => Facility::STATUS_ACTIVE,
                'address' => [],
                'settings' => [],
                'created_by' => $context->user?->getKey(),
            ]);

            $admin = User::query()->create([
                'email' => strtolower(trim((string) $request->validated('adminEmail'))),
                'password_hash' => $request->validated('adminPassword'),
                'status' => User::STATUS_ACTIVE,
            ]);

            RoleAssignment::query()->create([
                'user_id' => $admin->getKey(),
                'role_id' => $adminRole->getKey(),
                'tenant_id' => $org->getKey(),
                'facility_id' => null,
                'branch_id' => null,
                'scope_type' => 'organization',
                'status' => RoleAssignment::STATUS_ACTIVE,
                'granted_by' => $context->user?->getKey(),
                'granted_at' => now(),
            ]);

            return $facility;
        });

        $event = $this->audit->record(
            'organization.provisioned',
            'organization',
            $org->getKey(),
            [
                'facilityId' => $facility->getKey(),
                'adminEmail' => $request->validated('adminEmail'),
            ],
            $request,
            tenantId: $org->getKey(),
            facilityId: $facility->getKey(),
        );

        return Envelope::success(
            data: [
                'organizationId' => $org->getKey(),
                'facilityId' => $facility->getKey(),
                'adminEmail' => $request->validated('adminEmail'),
                'status' => 'provisioned',
            ],
            status: 201,
            request: $request,
            headers: ['X-Audit-Event-Id' => (string) $event->getKey()],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $organization): array
    {
        return [
            'id' => $organization->getKey(),
            'code' => $organization->code,
            'name' => $organization->name,
            'status' => $organization->status,
            'currency' => $organization->currency,
            'timezone' => $organization->timezone,
            'locale' => $organization->locale,
        ];
    }
}
