<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FinancialPeriod;
use App\Models\Organization;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 17 — Financial period management controller.
 *
 * Covers: fiscal period CRUD, open/close/lock lifecycle.
 * Only open periods allow new expenses/budget postings.
 * Close and lock require higher authorization.
 */
final class FinancialPeriodController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /** GET /organizations/{org}/financial-periods */
    public function index(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: false);
        $context = TenantContext::current();

        $query = FinancialPeriod::query()
            ->where('tenant_id', $context->tenantId())
            ->orderByDesc('fiscal_year')
            ->orderByDesc('period_number');

        if ($request->has('fiscal_year')) {
            $query->where('fiscal_year', $request->input('fiscal_year'));
        }
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $periods = $query->paginate(25);

        return Envelope::success(data: $periods->through(fn ($p) => $this->present($p)), request: $request);
    }

    /** POST /organizations/{org}/financial-periods */
    public function store(Request $request, Organization $organization): JsonResponse
    {
        AccessCheck::organization($organization->getKey(), write: true);
        $context = TenantContext::current();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'fiscalYear' => 'required|integer|min:2020|max:2050',
            'periodNumber' => 'required|integer|min:1|max:13',
            'periodType' => 'nullable|string',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after:startDate',
        ]);

        // Unique constraint: tenant + facility + fiscal_year + period_number
        $exists = FinancialPeriod::where('tenant_id', $context->tenantId())
            ->where('facility_id', $context->facilityId())
            ->where('fiscal_year', $validated['fiscalYear'])
            ->where('period_number', $validated['periodNumber'])
            ->exists();

        if ($exists) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Financial period already exists for this fiscal year and period number.', 409);
        }

        $period = FinancialPeriod::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $context->facilityId(),
            'name' => $validated['name'],
            'fiscal_year' => $validated['fiscalYear'],
            'period_number' => $validated['periodNumber'],
            'period_type' => $validated['periodType'] ?? 'monthly',
            'start_date' => $validated['startDate'],
            'end_date' => $validated['endDate'],
            'status' => FinancialPeriod::STATUS_OPEN,
        ]);

        $this->audit->record('financial_period.created', 'financial_period', $period->getKey(), [
            'name' => $period->name,
            'fiscalYear' => $period->fiscal_year,
        ], $request);

        return Envelope::success(data: $this->present($period), status: 201, request: $request);
    }

    /** GET /financial-periods/{period} */
    public function show(FinancialPeriod $period, Request $request): JsonResponse
    {
        AccessCheck::scoped($period, read: true);

        return Envelope::success(data: $this->present($period), request: $request);
    }

    /** POST /financial-periods/{period}/close */
    public function close(FinancialPeriod $period, Request $request): JsonResponse
    {
        AccessCheck::scoped($period, write: true);

        if ($period->status !== FinancialPeriod::STATUS_OPEN) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only open periods can be closed.', 409);
        }

        $context = TenantContext::current();
        $staffId = $context->user?->staff()
            ->where('tenant_id', $period->tenant_id)
            ->where('facility_id', $period->facility_id)
            ->first()?->getKey();

        $period->update([
            'status' => FinancialPeriod::STATUS_CLOSED,
            'closed_by_staff_id' => $staffId,
            'closed_at' => now(),
        ]);

        $this->audit->record('financial_period.closed', 'financial_period', $period->getKey(), [], $request);

        return Envelope::success(data: $this->present($period->fresh()), request: $request);
    }

    /** POST /financial-periods/{period}/lock */
    public function lock(FinancialPeriod $period, Request $request): JsonResponse
    {
        AccessCheck::scoped($period, write: true);

        if ($period->status !== FinancialPeriod::STATUS_CLOSED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only closed periods can be locked.', 409);
        }

        $context = TenantContext::current();
        $staffId = $context->user?->staff()
            ->where('tenant_id', $period->tenant_id)
            ->where('facility_id', $period->facility_id)
            ->first()?->getKey();

        $period->update([
            'status' => FinancialPeriod::STATUS_LOCKED,
            'period_status' => 'locked',
            'locked_by_staff_id' => $staffId,
            'locked_at' => now(),
        ]);

        $this->audit->record('financial_period.locked', 'financial_period', $period->getKey(), [], $request);

        return Envelope::success(data: $this->present($period->fresh()), request: $request);
    }

    /** POST /financial-periods/{period}/reopen */
    public function reopen(FinancialPeriod $period, Request $request): JsonResponse
    {
        AccessCheck::scoped($period, write: true);

        if ($period->status === FinancialPeriod::STATUS_LOCKED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'A locked period cannot be reopened. This is an irreversible accounting control.', 409);
        }

        if ($period->status !== FinancialPeriod::STATUS_CLOSED) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only closed periods can be reopened.', 409);
        }

        $period->update([
            'status' => FinancialPeriod::STATUS_OPEN,
            'period_status' => 'open',
            'closed_by_staff_id' => null,
            'closed_at' => null,
        ]);

        $this->audit->record('financial_period.reopened', 'financial_period', $period->getKey(), [], $request);

        return Envelope::success(data: $this->present($period->fresh()), request: $request);
    }

    /** @return array<string, mixed> */
    private function present(FinancialPeriod $period): array
    {
        return [
            'id' => $period->getKey(),
            'name' => $period->name,
            'fiscalYear' => $period->fiscal_year,
            'periodNumber' => $period->period_number,
            'periodType' => $period->period_type,
            'startDate' => $period->start_date?->toDateString(),
            'endDate' => $period->end_date?->toDateString(),
            'status' => $period->status,
            'totalBudgetMinor' => $period->total_budget_minor,
            'totalExpensesMinor' => $period->total_expenses_minor,
            'totalRevenueMinor' => $period->total_revenue_minor,
            'closedAt' => $period->closed_at?->toIso8601String(),
        ];
    }
}
