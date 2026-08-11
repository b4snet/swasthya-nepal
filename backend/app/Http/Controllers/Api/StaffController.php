<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Models\Organization;
use App\Models\Staff;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff profiles (DATABASE.md §3.10): employment identity within the tenant,
 * distinct from the global login account.
 *
 *  - The license number is encrypted at rest and NEVER included in list
 *    responses, logs, or audit payloads; show() returns it only to
 *    staff:view holders.
 *  - Never deleted: 'departed' is a status (clinical history references the
 *    clinician).
 *  - Department moves must stay inside the same facility (request gate).
 *
 * Controller parameter names must match the route segment ({staff}) for
 * implicit model binding — a mismatched name silently autowires an empty
 * model instead of binding the row.
 */
final class StaffController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);

        $context = TenantContext::current();
        $query = Staff::query()
            ->with('department:id,code,name')
            ->where('tenant_id', $organization->getKey())
            ->orderBy('full_name');

        if (! $context->isPlatform && $context->facilityId() !== null) {
            $query->where('facility_id', $context->facilityId());
        }

        $staff = $query->get()
            ->map(fn (Staff $staffMember): array => [
                'id' => $staffMember->getKey(),
                'facilityId' => $staffMember->facility_id,
                'departmentId' => $staffMember->department_id,
                'department' => $staffMember->department ? ['id' => $staffMember->department->getKey(), 'code' => $staffMember->department->code, 'name' => $staffMember->department->name] : null,
                'employeeCode' => $staffMember->employee_code,
                'fullName' => $staffMember->full_name,
                'designation' => $staffMember->designation,
                'status' => $staffMember->status,
                'userId' => $staffMember->user_id,
                'hireDate' => $staffMember->hire_date?->toDateString(),
            ])
            ->values();

        return Envelope::success(data: $staff, request: $request);
    }

    public function store(StoreStaffRequest $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);

        $context = TenantContext::current();
        $facility = FacilityScope::resolve($request->validated('facilityId'), write: true);

        $staff = Staff::query()->create([
            'tenant_id' => $organization->getKey(),
            'facility_id' => $facility->getKey(),
            'department_id' => $request->validated('departmentId'),
            'user_id' => $request->validated('userId'),
            'employee_code' => $request->validated('employeeCode'),
            'full_name' => $request->validated('fullName'),
            'designation' => $request->validated('designation'),
            'license_number_encrypted' => $request->validated('licenseNumber'),
            'status' => $request->validated('status', 'active'),
            'hire_date' => $request->validated('hireDate'),
            'settings' => [],
            'created_by' => $context->user?->getKey(),
        ]);

        $event = $this->audit->record(
            'staff.created',
            'staff',
            $staff->getKey(),
            [
                'employeeCode' => $staff->employee_code,
                'departmentId' => $staff->department_id,
                'facilityId' => $staff->facility_id,
                'designation' => $staff->designation,
                'status' => $staff->status,
                'hasLicense' => $staff->license_number_encrypted !== null,
            ],
            $request,
        );

        return Envelope::success(
            data: self::present($staff, includeLicense: false),
            status: 201,
            request: $request,
            headers: [
                'X-Audit-Event-Id' => (string) $event->getKey(),
                'Location' => '/api/v1/staff/'.$staff->getKey(),
            ],
        );
    }

    public function show(Request $request, Staff $staff): JsonResponse
    {
        AccessCheck::scoped($staff, write: false);

        return Envelope::success(data: self::present($staff, includeLicense: true), request: $request);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        AccessCheck::scoped($staff, write: true);

        $changes = [];
        foreach (['fullName' => 'full_name', 'designation' => 'designation', 'status' => 'status'] as $input => $field) {
            if ($request->has($input)) {
                $changes[$field] = [$staff->getAttribute($field), $request->validated($input)];
                $staff->setAttribute($field, $request->validated($input));
            }
        }

        if ($request->has('departmentId')) {
            $changes['departmentId'] = [$staff->department_id, $request->validated('departmentId')];
            $staff->department_id = $request->validated('departmentId');
        }

        if ($request->has('hireDate')) {
            $changes['hireDate'] = [$staff->hire_date?->toDateString(), $request->validated('hireDate')];
            $staff->hire_date = $request->validated('hireDate');
        }

        if ($request->has('licenseNumber')) {
            $changes['licenseChanged'] = [true, $request->validated('licenseNumber') !== null];
            $staff->license_number_encrypted = $request->validated('licenseNumber');
        }

        $staff->updated_by = TenantContext::current()->user?->getKey();
        $staff->save();

        $this->audit->record(
            'staff.updated',
            'staff',
            $staff->getKey(),
            [
                'changes' => $changes,
                'status' => $staff->status,
            ],
            $request,
        );

        return Envelope::success(data: self::present($staff, includeLicense: false), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(Staff $staff, bool $includeLicense): array
    {
        $payload = [
            'id' => $staff->getKey(),
            'facilityId' => $staff->facility_id,
            'departmentId' => $staff->department_id,
            'employeeCode' => $staff->employee_code,
            'fullName' => $staff->full_name,
            'designation' => $staff->designation,
            'status' => $staff->status,
            'userId' => $staff->user_id,
            'hireDate' => $staff->hire_date?->toDateString(),
        ];

        if ($includeLicense) {
            $payload['licenseNumber'] = $staff->license_number_encrypted;
        }

        return $payload;
    }
}
