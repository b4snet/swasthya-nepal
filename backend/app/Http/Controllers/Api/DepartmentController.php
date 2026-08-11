<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Department\StoreDepartmentRequest;
use App\Http\Requests\Department\UpdateDepartmentRequest;
use App\Models\Department;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Departments (DATABASE.md §3.8).
 *
 *  - index: departments of the organization, filtered to the caller's
 *    facility scope (TENANCY.md §7).
 *  - store: creates inside the caller's facility (context) or a named
 *    facility within the org.
 *  - destroy: soft delete; RESTRICT while staff reference the department —
 *    an FK violation becomes 409, never a raw 500.
 */
final class DepartmentController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Department::query()
            ->where('tenant_id', $organization->getKey())
            ->orderBy('name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $departments = $query->get(['id', 'facility_id', 'branch_id', 'name', 'code', 'status', 'parent_department_id'])
            ->map(fn (Department $department): array => [
                'id' => $department->getKey(),
                'facilityId' => $department->facility_id,
                'branchId' => $department->branch_id,
                'name' => $department->name,
                'code' => $department->code,
                'status' => $department->status,
                'parentDepartmentId' => $department->parent_department_id,
            ])
            ->values();

        return Envelope::success(data: $departments, request: $request);
    }

    public function store(StoreDepartmentRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $department = Department::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'branch_id' => FacilityScope::resolveBranch($request->validated('branchId'), $organization->getKey(), $facility->getKey()),
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'parent_department_id' => $request->validated('parentDepartmentId'),
            'status' => $request->validated('status', 'active'),
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'department.created',
            'department',
            $department->getKey(),
            [
                'code' => $department->code,
                'name' => $department->name,
                'facilityId' => $department->facility_id,
                'parentDepartmentId' => $department->parent_department_id,
            ],
            $request,
        );

        return Envelope::success(
            data: self::present($department),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/departments/'.$department->getKey(),
            ],
        );
    }

    public function show(Request $request, Department $department): JsonResponse
    {
        AccessCheck::scoped($department, write: false);

        return Envelope::success(data: self::present($department), request: $request);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        AccessCheck::scoped($department, write: true);

        $changes = [];
        foreach (['name', 'code', 'status'] as $field) {
            if ($request->has($field)) {
                $changes[$field] = [$department->getAttribute($field), $request->validated($field)];
                $department->setAttribute($field, $request->validated($field));
            }
        }

        if ($request->has('branchId')) {
            $changes['branchId'] = [$department->branch_id, $request->validated('branchId')];
            $department->branch_id = FacilityScope::resolveBranch(
                $request->validated('branchId'),
                $department->tenant_id,
                $department->facility_id,
            );
        }

        if ($request->has('parentDepartmentId')) {
            $changes['parentDepartmentId'] = [$department->parent_department_id, $request->validated('parentDepartmentId')];
            $department->parent_department_id = $request->validated('parentDepartmentId');
        }

        $department->updated_by = TenantContext::current()->user?->getKey();
        $department->save();

        $this->audit->record(
            'department.updated',
            'department',
            $department->getKey(),
            ['changes' => $changes],
            $request,
        );

        return Envelope::success(data: self::present($department), request: $request);
    }

    public function destroy(Request $request, Department $department): JsonResponse
    {
        AccessCheck::scoped($department, write: true);

        // Soft delete would not trip the RESTRICT FK — guard explicitly so a
        // department with staff or children can never be removed (DATABASE.md
        // §3.8: hierarchy and staff history must persist).
        if ($department->staff()->exists() || $department->children()->exists()) {
            return Envelope::error(
                'CONFLICT',
                'This department cannot be deleted while staff or child departments reference it.',
                409,
                request: $request,
            );
        }

        try {
            $department->delete();
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23503') {
                return Envelope::error(
                    'CONFLICT',
                    'This department cannot be deleted while staff or child departments reference it.',
                    409,
                    request: $request,
                );
            }

            throw $exception;
        }

        $this->audit->record(
            'department.deleted',
            'department',
            $department->getKey(),
            ['code' => $department->code, 'name' => $department->name],
            $request,
        );

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Department $department): array
    {
        return [
            'id' => $department->getKey(),
            'facilityId' => $department->facility_id,
            'branchId' => $department->branch_id,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
            'parentDepartmentId' => $department->parent_department_id,
        ];
    }
}
