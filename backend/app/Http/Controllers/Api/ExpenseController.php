<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\Expense;
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
 * Phase 17 — Expense management controller.
 *
 * Covers: expense CRUD, approval workflow, budget validation, payment tracking.
 * All mutations are transactional, authorization-controlled, tenant/facility isolated.
 * Requester ≠ approver (segregation of duties).
 */
final class ExpenseController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** GET /organizations/{org}/expenses */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);
        $context = TenantContext::current();

        $query = Expense::query()
            ->where('tenant_id', $context->tenantId())
            ->with('category:id,name,code')
            ->orderByDesc('expense_date');

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->has('category_id')) {
            $query->where('expense_category_id', $request->input('category_id'));
        }
        if ($request->has('budget_id')) {
            $query->where('budget_id', $request->input('budget_id'));
        }

        $expenses = $query->paginate(25);

        return Envelope::success(data: $expenses->through(fn ($e) => $this->present($e)), request: $request);
    }

    /** POST /organizations/{org}/expenses */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);
        $context = TenantContext::current();

        $validated = $request->validate([
            'description' => 'required|string|max:500',
            'amountMinor' => 'required|integer|min:1',
            'expenseCategoryId' => 'required|uuid',
            'expenseDate' => 'required|date',
            'expenseType' => 'nullable|string',
            'budgetId' => 'nullable|uuid',
            'budgetLineId' => 'nullable|uuid',
            'vendorId' => 'nullable|uuid',
            'invoiceNumber' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $expense = DB::transaction(function () use ($validated, $context): Expense {
            // Validate budget if provided
            if (! empty($validated['budgetId'])) {
                $budget = Budget::where('id', $validated['budgetId'])
                    ->where('tenant_id', $context->tenantId())
                    ->firstOrFail();

                if ($budget->status !== Budget::STATUS_ACTIVE) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'Budget must be active to post expenses.', 409);
                }

                if ($budget->remainingMinor() < $validated['amountMinor']) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'Insufficient budget allocation. Remaining: Rs '.($budget->remainingMinor() / 100), 409);
                }

                // Update budget spent
                $budget->increment('spent_minor', $validated['amountMinor']);

                if (! empty($validated['budgetLineId'])) {
                    BudgetLine::where('id', $validated['budgetLineId'])
                        ->where('budget_id', $budget->getKey())
                        ->increment('spent_minor', $validated['amountMinor']);
                }
            }

            $staffId = $context->user?->staff()
                ->where('tenant_id', $context->tenantId())
                ->where('facility_id', $context->facilityId())
                ->first()?->getKey();

            return Expense::create([
                'tenant_id' => $context->tenantId(),
                'facility_id' => $context->facilityId(),
                'budget_id' => $validated['budgetId'] ?? null,
                'budget_line_id' => $validated['budgetLineId'] ?? null,
                'expense_category_id' => $validated['expenseCategoryId'],
                'reference_number' => 'EXP-'.strtoupper(uniqid()),
                'description' => $validated['description'],
                'amount_minor' => $validated['amountMinor'],
                'currency' => 'NPR',
                'status' => Expense::STATUS_DRAFT,
                'expense_type' => $validated['expenseType'] ?? Expense::TYPE_OPERATIONAL,
                'vendor_id' => $validated['vendorId'] ?? null,
                'invoice_number' => $validated['invoiceNumber'] ?? null,
                'expense_date' => $validated['expenseDate'],
                'requested_by_staff_id' => $staffId,
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        $this->audit->record('expense.created', 'expense', $expense->getKey(), [
            'referenceNumber' => $expense->reference_number,
            'amountMinor' => $expense->amount_minor,
            'categoryId' => $expense->expense_category_id,
        ], $request);

        return Envelope::success(data: $this->present($expense), status: 201, request: $request);
    }

    /** GET /expenses/{expense} */
    public function show(Expense $expense, Request $request): JsonResponse
    {
        AccessCheck::scoped($expense, read: true);
        $expense->load('category', 'vendor', 'budget');

        return Envelope::success(data: $this->present($expense), request: $request);
    }

    /** POST /expenses/{expense}/submit */
    public function submit(Expense $expense, Request $request): JsonResponse
    {
        AccessCheck::scoped($expense, write: true);

        if ($expense->status !== Expense::STATUS_DRAFT) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only draft expenses can be submitted.', 409);
        }

        $expense->update(['status' => Expense::STATUS_PENDING_APPROVAL]);

        $this->audit->record('expense.submitted', 'expense', $expense->getKey(), [], $request);

        return Envelope::success(data: $this->present($expense->fresh()), request: $request);
    }

    /** POST /expenses/{expense}/approve */
    public function approve(Expense $expense, Request $request): JsonResponse
    {
        AccessCheck::scoped($expense, write: true);

        if ($expense->status !== Expense::STATUS_PENDING_APPROVAL) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only pending expenses can be approved.', 409);
        }

        // Segregation of duties: requester ≠ approver
        $context = TenantContext::current();
        $approverStaff = $context->user?->staff()
            ->where('tenant_id', $expense->tenant_id)
            ->where('facility_id', $expense->facility_id)
            ->first();

        if ($approverStaff && $approverStaff->getKey() === $expense->requested_by_staff_id) {
            throw new ApiException(ErrorCodes::FORBIDDEN, 'Requester cannot approve their own expense.', 403);
        }

        $expense->update([
            'status' => Expense::STATUS_APPROVED,
            'approved_by_staff_id' => $approverStaff?->getKey(),
            'approved_at' => now(),
        ]);

        $this->audit->record('expense.approved', 'expense', $expense->getKey(), [
            'amountMinor' => $expense->amount_minor,
        ], $request);

        return Envelope::success(data: $this->present($expense->fresh()), request: $request);
    }

    /** POST /expenses/{expense}/reject */
    public function reject(Request $request, Expense $expense): JsonResponse
    {
        AccessCheck::scoped($expense, write: true);

        if ($expense->status !== Expense::STATUS_PENDING_APPROVAL) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only pending expenses can be rejected.', 409);
        }

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        DB::transaction(function () use ($expense, $validated): void {
            $expense->update([
                'status' => Expense::STATUS_REJECTED,
                'rejection_reason' => $validated['reason'],
            ]);

            // Reverse budget allocation if budget-linked
            if ($expense->budget_id) {
                Budget::where('id', $expense->budget_id)->decrement('spent_minor', $expense->amount_minor);
                if ($expense->budget_line_id) {
                    BudgetLine::where('id', $expense->budget_line_id)->decrement('spent_minor', $expense->amount_minor);
                }
            }
        });

        $this->audit->record('expense.rejected', 'expense', $expense->getKey(), [
            'reason' => $validated['reason'],
        ], $request);

        return Envelope::success(data: $this->present($expense->fresh()), request: $request);
    }

    /** POST /expenses/{expense}/pay */
    public function pay(Request $request, Expense $expense): JsonResponse
    {
        AccessCheck::scoped($expense, write: true);

        if ($expense->status !== Expense::STATUS_APPROVED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only approved expenses can be paid.', 409);
        }

        $validated = $request->validate([
            'paymentMethod' => 'required|string',
            'paymentReference' => 'nullable|string',
        ]);

        $expense->update([
            'status' => Expense::STATUS_PAID,
            'payment_date' => now(),
            'payment_method' => $validated['paymentMethod'],
            'payment_reference' => $validated['paymentReference'] ?? null,
        ]);

        $this->audit->record('expense.paid', 'expense', $expense->getKey(), [
            'paymentMethod' => $expense->payment_method,
            'amountMinor' => $expense->amount_minor,
        ], $request);

        return Envelope::success(data: $this->present($expense->fresh()), request: $request);
    }

    /** POST /expenses/{expense}/void */
    public function void(Expense $expense, Request $request): JsonResponse
    {
        AccessCheck::scoped($expense, write: true);

        if (in_array($expense->status, [Expense::STATUS_VOID], true)) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Expense is already void.', 409);
        }

        DB::transaction(function () use ($expense): void {
            $expense->update(['status' => Expense::STATUS_VOID]);

            // Reverse budget allocation if budget-linked
            if ($expense->budget_id) {
                Budget::where('id', $expense->budget_id)->decrement('spent_minor', $expense->amount_minor);
                if ($expense->budget_line_id) {
                    BudgetLine::where('id', $expense->budget_line_id)->decrement('spent_minor', $expense->amount_minor);
                }
            }
        });

        $this->audit->record('expense.voided', 'expense', $expense->getKey(), [], $request);

        return Envelope::success(data: $this->present($expense->fresh()), request: $request);
    }

    /** @return array<string, mixed> */
    private function present(Expense $expense): array
    {
        return [
            'id' => $expense->getKey(),
            'referenceNumber' => $expense->reference_number,
            'description' => $expense->description,
            'amountMinor' => $expense->amount_minor,
            'currency' => $expense->currency,
            'status' => $expense->status,
            'expenseType' => $expense->expense_type,
            'expenseDate' => $expense->expense_date?->toIso8601String(),
            'paymentDate' => $expense->payment_date?->toIso8601String(),
            'paymentMethod' => $expense->payment_method,
            'vendorId' => $expense->vendor_id,
            'budgetId' => $expense->budget_id,
            'invoiceNumber' => $expense->invoice_number,
            'category' => $expense->relationLoaded('category') && $expense->category
                ? ['id' => $expense->category->getKey(), 'name' => $expense->category->name, 'code' => $expense->category->code]
                : null,
            'approvedAt' => $expense->approved_at?->toIso8601String(),
            'rejectionReason' => $expense->rejection_reason,
            'notes' => $expense->notes,
        ];
    }
}
