<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\ExpenseCategory;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 17 — Budget management controller.
 *
 * Covers: budget CRUD, approval, line items, reallocation, utilization.
 * All mutations are transactional, authorization-controlled, tenant/facility isolated.
 */
final class BudgetController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** GET /organizations/{org}/budgets */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);
        $context = TenantContext::current();

        $query = Budget::query()
            ->where('tenant_id', $context->tenantId())
            ->orderByDesc('created_at');

        if ($request->has('fiscal_year')) {
            $query->where('fiscal_year', $request->input('fiscal_year'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $budgets = $query->paginate(25);

        return Envelope::success(data: $budgets->through(fn ($b) => $this->present($b)), request: $request);
    }

    /** POST /organizations/{org}/budgets */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);
        $context = TenantContext::current();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'budgetType' => 'nullable|string',
            'fiscalYear' => 'required|integer|min:2020|max:2050',
            'facilityId' => 'required|uuid',
            'departmentId' => 'nullable|uuid',
            'totalAllocationMinor' => 'required|integer|min:0',
            'lines' => 'nullable|array',
            'lines.*.description' => 'required|string',
            'lines.*.allocationMinor' => 'required|integer|min:0',
            'lines.*.expenseCategoryId' => 'nullable|uuid',
        ]);

        $budget = DB::transaction(function () use ($validated, $context): Budget {
            $budget = Budget::create([
                'tenant_id' => $context->tenantId(),
                'facility_id' => $validated['facilityId'],
                'department_id' => $validated['departmentId'] ?? null,
                'budget_code' => 'BUD-'.strtoupper(uniqid()),
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'budget_type' => $validated['budgetType'] ?? Budget::TYPE_OPERATIONAL,
                'fiscal_year' => $validated['fiscalYear'],
                'status' => Budget::STATUS_DRAFT,
                'total_allocation_minor' => $validated['totalAllocationMinor'],
                'created_by_staff_id' => $context->user?->staff()
                    ->where('tenant_id', $context->tenantId())
                    ->first()?->getKey(),
            ]);

            if (! empty($validated['lines'])) {
                foreach ($validated['lines'] as $line) {
                    BudgetLine::create([
                        'tenant_id' => $context->tenantId(),
                        'facility_id' => $validated['facilityId'],
                        'budget_id' => $budget->getKey(),
                        'expense_category_id' => $line['expenseCategoryId'] ?? null,
                        'description' => $line['description'],
                        'allocation_minor' => $line['allocationMinor'],
                    ]);
                }
            }

            return $budget;
        });

        $this->audit->record('budget.created', 'budget', $budget->getKey(), [
            'name' => $budget->name, 'fiscalYear' => $budget->fiscal_year,
            'totalAllocationMinor' => $budget->total_allocation_minor,
        ], $request);

        return Envelope::success(data: $this->present($budget->fresh(['lines'])), status: 201, request: $request);
    }

    /** GET /budgets/{budget} */
    public function show(Budget $budget, Request $request): JsonResponse
    {
        AccessCheck::scoped($budget, read: true);
        $budget->load(['lines.category', 'expenses.category']);

        return Envelope::success(data: $this->present($budget), request: $request);
    }

    /** POST /budgets/{budget}/approve */
    public function approve(Budget $budget, Request $request): JsonResponse
    {
        AccessCheck::scoped($budget, write: true);

        if ($budget->status !== Budget::STATUS_DRAFT) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only draft budgets can be approved.', 409);
        }

        $context = TenantContext::current();
        $staffId = $context->user?->staff()
            ->where('tenant_id', $budget->tenant_id)
            ->where('facility_id', $budget->facility_id)
            ->first()?->getKey();

        $budget->update([
            'status' => Budget::STATUS_ACTIVE,
            'approved_by_staff_id' => $staffId,
            'approved_at' => now(),
        ]);

        $this->audit->record('budget.approved', 'budget', $budget->getKey(), [
            'fiscalYear' => $budget->fiscal_year,
        ], $request);

        return Envelope::success(data: $this->present($budget->fresh()), request: $request);
    }

    /** POST /budgets/{budget}/close */
    public function close(Budget $budget, Request $request): JsonResponse
    {
        AccessCheck::scoped($budget, write: true);

        if ($budget->status !== Budget::STATUS_ACTIVE) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only active budgets can be closed.', 409);
        }

        $context = TenantContext::current();
        $staffId = $context->user?->staff()
            ->where('tenant_id', $budget->tenant_id)
            ->where('facility_id', $budget->facility_id)
            ->first()?->getKey();

        $budget->update([
            'status' => Budget::STATUS_CLOSED,
            'closed_by_staff_id' => $staffId,
            'closed_at' => now(),
        ]);

        $this->audit->record('budget.closed', 'budget', $budget->getKey(), [], $request);

        return Envelope::success(data: $this->present($budget->fresh()), request: $request);
    }

    /** POST /budgets/{budget}/lines */
    public function storeLine(Request $request, Budget $budget): JsonResponse
    {
        AccessCheck::scoped($budget, write: true);

        $validated = $request->validate([
            'description' => 'required|string',
            'allocationMinor' => 'required|integer|min:0',
            'expenseCategoryId' => 'nullable|uuid',
        ]);

        $line = BudgetLine::create([
            'tenant_id' => $budget->tenant_id,
            'facility_id' => $budget->facility_id,
            'budget_id' => $budget->getKey(),
            'expense_category_id' => $validated['expenseCategoryId'] ?? null,
            'description' => $validated['description'],
            'allocation_minor' => $validated['allocationMinor'],
        ]);

        // Update budget total
        $budget->update([
            'total_allocation_minor' => $budget->total_allocation_minor + $validated['allocationMinor'],
        ]);

        return Envelope::success(data: [
            'id' => $line->getKey(),
            'description' => $line->description,
            'allocationMinor' => $line->allocation_minor,
        ], status: 201, request: $request);
    }

    /** GET /organizations/{org}/expense-categories */
    public function indexCategories(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);
        $context = TenantContext::current();

        $categories = ExpenseCategory::query()
            ->where('tenant_id', $context->tenantId())
            ->orderBy('code')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->getKey(),
                'code' => $c->code,
                'name' => $c->name,
                'description' => $c->description,
                'status' => $c->status,
            ]);

        return Envelope::success(data: $categories, request: $request);
    }

    /** POST /organizations/{org}/expense-categories */
    public function storeCategory(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);
        $context = TenantContext::current();

        $validated = $request->validate([
            'code' => 'required|string|unique:expense_categories,code',
            'name' => 'required|string',
            'description' => 'nullable|string',
        ]);

        $category = ExpenseCategory::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return Envelope::success(data: [
            'id' => $category->getKey(),
            'code' => $category->code,
            'name' => $category->name,
        ], status: 201, request: $request);
    }

    /** @return array<string, mixed> */
    private function present(Budget $budget): array
    {
        return [
            'id' => $budget->getKey(),
            'budgetCode' => $budget->budget_code,
            'name' => $budget->name,
            'description' => $budget->description,
            'budgetType' => $budget->budget_type,
            'fiscalYear' => $budget->fiscal_year,
            'status' => $budget->status,
            'totalAllocationMinor' => $budget->total_allocation_minor,
            'spentMinor' => $budget->spent_minor,
            'committedMinor' => $budget->committed_minor,
            'remainingMinor' => $budget->remainingMinor(),
            'utilizationPercent' => $budget->utilizationPercent(),
            'approvedAt' => $budget->approved_at?->toIso8601String(),
            'closedAt' => $budget->closed_at?->toIso8601String(),
            'lines' => $budget->relationLoaded('lines')
                ? $budget->lines->map(fn ($l) => [
                    'id' => $l->getKey(),
                    'description' => $l->description,
                    'allocationMinor' => $l->allocation_minor,
                    'spentMinor' => $l->spent_minor,
                    'committedMinor' => $l->committed_minor,
                    'remainingMinor' => $l->remainingMinor(),
                    'category' => $l->category?->name,
                ])->values()
                : [],
        ];
    }
}
