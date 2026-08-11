<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\StoreBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\Facility;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branches (TENANCY.md V2 §4): the optional operational sub-division of a
 * facility. Branches are created inside a facility — the facility is the
 * tenant anchor, so a branch can never belong to another tenant or facility
 * (composite FK). All mutations are audited; codes are unique per
 * (tenant, facility) among live branches.
 */
final class BranchController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: false);

        $branches = Branch::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'status'])
            ->map(fn (Branch $branch): array => self::present($branch))
            ->values();

        return Envelope::success(data: $branches, request: $request);
    }

    public function store(StoreBranchRequest $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: true);

        $context = TenantContext::current();

        $branch = Branch::query()->create([
            'tenant_id' => $facility->tenant_id,
            'facility_id' => $facility->getKey(),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'status' => $request->validated('status', 'active'),
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'branch.created',
            'branch',
            $branch->getKey(),
            ['code' => $branch->code, 'name' => $branch->name, 'facilityId' => $branch->facility_id],
            $request,
        );

        return Envelope::success(
            data: self::present($branch),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/branches/'.$branch->getKey(),
            ],
        );
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        AccessCheck::scoped($branch, write: false);

        return Envelope::success(data: self::present($branch), request: $request);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        AccessCheck::scoped($branch, write: true);

        $changes = [];
        foreach (['name', 'code', 'status'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = [$branch->getAttribute($field), $request->validated($field)];
                $branch->setAttribute($field, $request->validated($field));
            }
        }

        $branch->updated_by = TenantContext::current()->user?->getKey();
        $branch->save();

        $this->audit->record(
            'branch.updated',
            'branch',
            $branch->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: self::present($branch), request: $request);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        AccessCheck::scoped($branch, write: true);

        $branch->delete();

        $this->audit->record(
            'branch.deleted',
            'branch',
            $branch->getKey(),
            ['code' => $branch->code, 'name' => $branch->name],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Branch $branch): array
    {
        return [
            'id' => $branch->getKey(),
            'facilityId' => $branch->facility_id,
            'name' => $branch->name,
            'code' => $branch->code,
            'status' => $branch->status,
        ];
    }
}
