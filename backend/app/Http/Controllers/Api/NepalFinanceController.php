<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FinancialPeriod;
use App\Models\InsuranceClaim;
use App\Models\Payer;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\FacilityScope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller for the Nepal Financial Administration page.
 *
 * Provides simplified endpoints under /finance/ that the frontend admin
 * page calls. These delegate to the same models and tenant context as the
 * existing controllers but use a simpler URL structure.
 */
final class NepalFinanceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /* ── Fiscal Years ────────────────────────────────────────── */

    /** GET /finance/fiscal-years */
    public function indexFiscalYears(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $query = FinancialPeriod::query()
            ->where('tenant_id', $context->tenantId())
            ->orderByDesc('fiscal_year')
            ->orderByDesc('period_number');

        if ($request->has('facilityId') && $request->input('facilityId')) {
            $query->where('facility_id', $request->input('facilityId'));
        }

        $periods = $query->get()->map(fn (FinancialPeriod $p): array => self::presentFiscalYear($p));

        return Envelope::success(data: $periods, request: $request);
    }

    /** POST /finance/fiscal-years */
    public function storeFiscalYear(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'fiscalYear' => 'required|integer|min:2020|max:2090',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after:startDate',
            'calendarType' => 'nullable|string|in:gregorian,nepal_fiscal',
            'nepalFiscalYear' => 'nullable|string|max:20',
            'periodNumber' => 'nullable|integer|min:1|max:13',
        ]);

        $context = TenantContext::current();
        $facilityId = $request->input('facilityId');

        // Calculate period number from start date if not provided
        $periodNumber = $validated['periodNumber'] ?? (int) \Carbon\Carbon::parse($validated['startDate'])->format('m');

        $exists = FinancialPeriod::where('tenant_id', $context->tenantId())
            ->where('fiscal_year', $validated['fiscalYear'])
            ->where('period_number', $periodNumber)
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->exists();

        if ($exists) {
            throw new ApiException(ErrorCodes::CONFLICT, 'Fiscal period already exists for this year and period.', 409);
        }

        $period = FinancialPeriod::create([
            'tenant_id' => $context->tenantId(),
            'facility_id' => $facilityId,
            'name' => $validated['name'],
            'fiscal_year' => $validated['fiscalYear'],
            'period_number' => $periodNumber,
            'period_type' => 'fiscal_year',
            'start_date' => $validated['startDate'],
            'end_date' => $validated['endDate'],
            'status' => FinancialPeriod::STATUS_OPEN,
            'calendar_type' => $validated['calendarType'] ?? 'nepal_fiscal',
            'nepal_fiscal_year' => $validated['nepalFiscalYear'] ?? null,
            'period_status' => 'open',
        ]);

        $this->audit->record('nepal_finance.fiscal_year.created', 'financial_period', $period->getKey(), [
            'name' => $period->name,
            'fiscalYear' => $period->fiscal_year,
        ], $request);

        return Envelope::success(
            data: self::presentFiscalYear($period),
            status: 201,
            request: $request,
        );
    }

    /** POST /finance/fiscal-years/{period}/close */
    public function closeFiscalYear(Request $request, FinancialPeriod $period): JsonResponse
    {
        AccessCheck::scoped($period, write: true);

        if ($period->status === FinancialPeriod::STATUS_CLOSED || $period->period_status === 'closed') {
            throw new ApiException(ErrorCodes::CONFLICT, 'Period is already closed.', 409);
        }

        $period->update([
            'status' => FinancialPeriod::STATUS_CLOSED,
            'period_status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->audit->record('nepal_finance.fiscal_year.closed', 'financial_period', $period->getKey(), [], $request);

        return Envelope::success(data: self::presentFiscalYear($period->fresh()), request: $request);
    }

    /** POST /finance/fiscal-years/{period}/reopen */
    public function reopenFiscalYear(Request $request, FinancialPeriod $period): JsonResponse
    {
        AccessCheck::scoped($period, write: true);

        if ($period->status === FinancialPeriod::STATUS_LOCKED || $period->period_status === 'locked') {
            throw new ApiException(ErrorCodes::CONFLICT, 'A locked period cannot be reopened. This is an irreversible accounting control.', 409);
        }

        if ($period->status !== FinancialPeriod::STATUS_CLOSED && $period->period_status !== 'closed') {
            throw new ApiException(ErrorCodes::CONFLICT, 'Only closed periods can be reopened.', 409);
        }

        $period->update([
            'status' => FinancialPeriod::STATUS_OPEN,
            'period_status' => 'open',
            'closed_by_staff_id' => null,
            'closed_at' => null,
        ]);

        $this->audit->record('nepal_finance.fiscal_year.reopened', 'financial_period', $period->getKey(), [], $request);

        return Envelope::success(data: self::presentFiscalYear($period->fresh()), request: $request);
    }

    /* ── Payers ─────────────────────────────────────────────── */

    /** GET /finance/payers */
    public function indexPayers(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $payers = Payer::query()
            ->where('tenant_id', $context->tenantId())
            ->orderBy('name')
            ->get()
            ->map(fn (Payer $payer): array => self::presentPayer($payer))
            ->values();

        return Envelope::success(data: $payers, request: $request);
    }

    /** POST /finance/payers */
    public function storePayer(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',
            'payerType' => 'required|string|in:insurance,government,self_pay',
            'payerSubType' => 'nullable|string|in:ssf,hib,private,corporate,government,other',
            'schemeVersion' => 'nullable|string|max:50',
        ]);

        $context = TenantContext::current();

        $exists = Payer::where('tenant_id', $context->tenantId())
            ->whereRaw('lower(code) = ?', [strtolower($validated['code'])])
            ->exists();

        if ($exists) {
            throw new ApiException(ErrorCodes::CONFLICT, 'A payer with this code already exists.', 409);
        }

        $payer = Payer::create([
            'tenant_id' => $context->tenantId(),
            'name' => $validated['name'],
            'code' => $validated['code'],
            'payer_type' => $validated['payerType'],
            'payer_sub_type' => $validated['payerSubType'] ?? null,
            'scheme_version' => $validated['schemeVersion'] ?? null,
            'status' => Payer::STATUS_ACTIVE,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record('payer.created', 'payer', $payer->getKey(), [
            'code' => $payer->code,
            'name' => $payer->name,
            'payerType' => $payer->payer_type,
            'payerSubType' => $payer->payer_sub_type,
        ], $request);

        return Envelope::success(
            data: self::presentPayer($payer),
            status: 201,
            request: $request,
        );
    }

    /* ── Claims ─────────────────────────────────────────────── */

    /** GET /finance/claims */
    public function indexClaims(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $claims = InsuranceClaim::query()
            ->where('tenant_id', $context->tenantId())
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (InsuranceClaim $claim): array => self::presentClaim($claim))
            ->values();

        return Envelope::success(data: $claims, request: $request);
    }

    /* ── Presenters ─────────────────────────────────────────── */

    private static function presentFiscalYear(FinancialPeriod $p): array
    {
        return [
            'id' => $p->getKey(),
            'name' => $p->name,
            'fiscal_year' => $p->fiscal_year,
            'calendar_type' => $p->calendar_type ?? 'gregorian',
            'nepal_fiscal_year' => $p->nepal_fiscal_year,
            'start_date' => $p->start_date?->toDateString(),
            'end_date' => $p->end_date?->toDateString(),
            'status' => $p->status,
            'period_status' => $p->period_status ?? $p->status,
            'closed_at' => $p->closed_at?->toIso8601String(),
            'locked_at' => $p->locked_at?->toIso8601String(),
        ];
    }

    private static function presentPayer(Payer $payer): array
    {
        return [
            'id' => $payer->getKey(),
            'name' => $payer->name,
            'code' => $payer->code,
            'payer_type' => $payer->payer_type,
            'payer_sub_type' => $payer->payer_sub_type,
            'scheme_version' => $payer->scheme_version,
            'status' => $payer->status,
        ];
    }

    private static function presentClaim(InsuranceClaim $claim): array
    {
        return [
            'id' => $claim->getKey(),
            'claim_number' => $claim->claim_number,
            'claim_type' => $claim->claim_type ?? 'standard',
            'payer_id' => $claim->payer_id,
            'status' => $claim->status,
            'billed_total_minor' => $claim->billedTotalMinor(),
            'settlement_minor' => $claim->settlement_minor ?? 0,
        ];
    }
}
