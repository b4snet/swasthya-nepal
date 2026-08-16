<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\AllocateDepositRequest;
use App\Http\Requests\Billing\ReconcileSettlementRequest;
use App\Http\Requests\Billing\RecordClaimStatusRequest;
use App\Http\Requests\Billing\SettleClaimRequest;
use App\Http\Requests\Billing\StoreClaimRequest;
use App\Http\Requests\Billing\StoreDepositRequest;
use App\Models\Deposit;
use App\Models\InsuranceClaim;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Settlement;
use App\Models\Staff;
use App\Services\FinanceService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 slice 18 — the remaining Billing and Finance surface
 * (PRODUCT_REQUIREMENTS §6.13–6.14, DATABASE.md §3.33–3.35): deposits
 * (collect/allocate), patient-account aging, daily cashier settlements,
 * and insurance claims (build/submit/track/settle).
 *
 * No payment gateway is connected (INTEROPERABILITY.md §13 — planned, no
 * provider contract exists); nothing here fakes an integration.
 */
final class FinanceController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FinanceService $finance,
    ) {}

    /**
     * GET patients/{patient}/deposits — the patient's deposits, newest first.
     */
    public function deposits(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $deposits = $patient->deposits()
            ->orderByDesc('collected_at')
            ->get()
            ->map(fn (Deposit $d): array => self::presentDeposit($d))
            ->values();

        return Envelope::success(data: $deposits, request: $request);
    }

    /**
     * POST patients/{patient}/deposits — collect an advance payment.
     */
    public function collectDeposit(StoreDepositRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();

        $deposit = $this->finance->collectDeposit(
            (string) $context->tenantId(),
            (string) $patient->facility_id,
            (string) $patient->getKey(),
            (int) $request->validated('amountMinor'),
            (string) $request->validated('idempotencyKey'),
            $this->currentStaffId($context, (string) $patient->facility_id),
        );

        $isNew = $deposit->wasRecentlyCreated;

        $this->audit->record(
            $isNew ? 'deposit.collected' : 'deposit.replayed',
            'deposit',
            $deposit->getKey(),
            ['patientId' => $patient->getKey(), 'amountMinor' => $deposit->amount_minor, 'replayed' => ! $isNew],
            $request,
        );

        return Envelope::success(
            data: self::presentDeposit($deposit),
            status: $isNew ? 201 : 200,
            request: $request,
        );
    }

    /**
     * POST deposits/{deposit}/allocate — apply part of a deposit to an
     * invoice (exact allocation, CAS on the remaining balance).
     */
    public function allocateDeposit(AllocateDepositRequest $request, Deposit $deposit): JsonResponse
    {
        AccessCheck::scoped($deposit, write: true);

        $context = TenantContext::current();

        $allocation = $this->finance->allocateDeposit(
            (string) $context->tenantId(),
            (string) $deposit->facility_id,
            (string) $deposit->getKey(),
            (string) $request->validated('invoiceId'),
            (int) $request->validated('amountMinor'),
            $this->currentStaffId($context, (string) $deposit->facility_id),
        );

        $this->audit->record('deposit.allocated', 'deposit_allocation', $allocation->getKey(), [
            'depositId' => $deposit->getKey(),
            'invoiceId' => $allocation->invoice_id,
            'amountMinor' => $allocation->amount_minor,
            'remainingMinor' => $deposit->refresh()->remaining_minor,
        ], $request);

        $deposit->refresh();

        return Envelope::success(data: [
            'allocationId' => $allocation->getKey(),
            'amountMinor' => $allocation->amount_minor,
            'invoiceId' => $allocation->invoice_id,
            'deposit' => self::presentDeposit($deposit),
        ], status: 201, request: $request);
    }

    /**
     * GET patients/{patient}/aging — patient-account aging (PRODUCT_REQUIREMENTS
     * §6.13): outstanding invoice balances bucketed by days since issue.
     * Computed from invoice truth — never a stored, stale figure.
     */
    public function aging(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $invoices = Invoice::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->whereIn('status', [Invoice::STATUS_ISSUED, Invoice::STATUS_PARTIALLY_PAID])
            ->orderBy('issued_at')
            ->get();

        $buckets = [
            'current' => 0,
            '30' => 0,
            '60' => 0,
            '90' => 0,
        ];

        $details = $invoices->map(function (Invoice $invoice) use (&$buckets): array {
            $outstanding = $invoice->total_minor + $invoice->total_tax_minor - $invoice->paid_minor;
            // Carbon 3's diffInDays is SIGNED (negative for past dates) —
            // abs() makes days-since-issue always positive.
            $days = (int) abs(now()->diffInDays($invoice->issued_at ?? now()));

            $bucket = match (true) {
                $days >= 90 => '90',
                $days >= 60 => '60',
                $days >= 30 => '30',
                default => 'current',
            };

            if ($outstanding > 0) {
                $buckets[$bucket] += $outstanding;
            }

            return [
                'invoiceId' => $invoice->getKey(),
                'invoiceNumber' => $invoice->invoice_number,
                'status' => $invoice->status,
                'totalMinor' => $invoice->total_minor,
                'totalTaxMinor' => $invoice->total_tax_minor,
                'paidMinor' => $invoice->paid_minor,
                'outstandingMinor' => $outstanding,
                'daysOutstanding' => $days,
                'bucket' => $bucket,
                'issuedAt' => $invoice->issued_at?->toIso8601String(),
            ];
        })->values();

        return Envelope::success(data: [
            'patientId' => $patient->getKey(),
            'totalOutstandingMinor' => array_sum($buckets),
            'buckets' => [
                ['bucket' => 'current', 'label' => '0–29 days', 'amountMinor' => $buckets['current']],
                ['bucket' => '30', 'label' => '30–59 days', 'amountMinor' => $buckets['30']],
                ['bucket' => '60', 'label' => '60–89 days', 'amountMinor' => $buckets['60']],
                ['bucket' => '90', 'label' => '90+ days', 'amountMinor' => $buckets['90']],
            ],
            'invoices' => $details,
        ], request: $request);
    }

    /**
     * GET cashier-settlements — the facility's settlement days, newest first.
     */
    public function settlements(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $settlements = Settlement::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->orderByDesc('settlement_date')
            ->get()
            ->map(fn (Settlement $s): array => self::presentSettlement($s))
            ->values();

        return Envelope::success(data: $settlements, request: $request);
    }

    /**
     * POST cashier-settlements/reconcile — reconcile a cashier's day.
     * Expected is the day's captured payments; a non-zero variance
     * disputes (never silently absorbed) and is audited.
     */
    public function reconcileSettlement(ReconcileSettlementRequest $request): JsonResponse
    {
        $context = TenantContext::current();

        $cashierId = $request->validated('cashierId');
        if ($cashierId === null) {
            $cashierId = $this->currentStaffId($context, (string) $context->facilityId());
        }

        // The named cashier must be an active staff member in the facility.
        $cashier = Staff::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('id', $cashierId)
            ->first();

        if ($cashier === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Cashier not found.', 404);
        }

        $settlement = $this->finance->reconcileSettlement(
            (string) $context->tenantId(),
            (string) $cashier->facility_id,
            (string) $cashier->getKey(),
            (string) ($request->validated('settlementDate') ?? now()->toDateString()),
            (int) $request->validated('actualMinor'),
            $this->currentStaffId($context, (string) $cashier->facility_id),
            $request->validated('notes'),
        );

        $this->audit->record('settlement.reconciled', 'settlement', $settlement->getKey(), [
            'cashierId' => $cashier->getKey(),
            'settlementDate' => $settlement->settlement_date->toDateString(),
            'expectedMinor' => $settlement->expected_minor,
            'actualMinor' => $settlement->actual_minor,
            'varianceMinor' => $settlement->variance_minor,
            'status' => $settlement->status,
        ], $request);

        return Envelope::success(data: self::presentSettlement($settlement), request: $request);
    }

    /**
     * GET invoices/{invoice}/claims — the invoice's claims, oldest first.
     */
    public function claims(Request $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: false);

        $claims = $invoice->claims()
            ->with('lines')
            ->orderBy('created_at')
            ->get()
            ->map(fn (InsuranceClaim $c): array => self::presentClaim($c))
            ->values();

        return Envelope::success(data: $claims, request: $request);
    }

    /**
     * POST invoices/{invoice}/claims — build a draft claim from the invoice
     * under the patient's policy (claim lines map exactly to invoice lines).
     */
    public function buildClaim(StoreClaimRequest $request, Invoice $invoice): JsonResponse
    {
        AccessCheck::scoped($invoice, write: true);

        $context = TenantContext::current();

        $claim = $this->finance->buildClaim(
            (string) $context->tenantId(),
            (string) $invoice->getKey(),
            (string) $request->validated('policyId'),
            $context->user?->getKey(),
        );

        $this->audit->record('insurance_claim.built', 'insurance_claim', $claim->getKey(), [
            'invoiceId' => $invoice->getKey(),
            'policyId' => $claim->policy_id,
            'lineCount' => $claim->lines->count(),
            'billedMinor' => $claim->billedTotalMinor(),
        ], $request);

        return Envelope::success(data: self::presentClaim($claim), status: 201, request: $request);
    }

    /**
     * GET claims/{claim} — the claim with its lines. Claims are TENANT-tier
     * (no facility_id — §3.35), so the scope gate runs through the claim's
     * invoice (the facility-carrying parent, the established pattern for
     * tenant-tier resources).
     */
    public function showClaim(Request $request, InsuranceClaim $claim): JsonResponse
    {
        AccessCheck::scoped($this->claimInvoice($claim), write: false);

        $claim->load('lines');

        return Envelope::success(data: self::presentClaim($claim), request: $request);
    }

    /**
     * POST claims/{claim}/submit — draft → submitted (CAS).
     */
    public function submitClaim(Request $request, InsuranceClaim $claim): JsonResponse
    {
        AccessCheck::scoped($this->claimInvoice($claim), write: true);

        $claim = $this->finance->submitClaim($claim, $request->user()?->getKey());

        $this->audit->record('insurance_claim.submitted', 'insurance_claim', $claim->getKey(), [
            'invoiceId' => $claim->invoice_id,
            'submittedAt' => $claim->submitted_at?->toIso8601String(),
        ], $request);

        return Envelope::success(data: self::presentClaim($claim), request: $request);
    }

    /**
     * POST claims/{claim}/reopen — reopen a DENIED claim for resubmission
     * (denied → draft, CAS). The denial stays in the audit trail; the same
     * claim is re-submitted — no duplicate rows, no fabricated lines.
     */
    public function reopenClaim(Request $request, InsuranceClaim $claim): JsonResponse
    {
        AccessCheck::scoped($this->claimInvoice($claim), write: true);

        $claim = $this->finance->reopenClaim($claim, $request->user()?->getKey());

        $this->audit->record('insurance_claim.reopened', 'insurance_claim', $claim->getKey(), [
            'invoiceId' => $claim->invoice_id,
        ], $request);

        return Envelope::success(data: self::presentClaim($claim), request: $request);
    }

    /**
     * POST claims/{claim}/status — record a non-monetary payer status
     * (pending | denied — a denial requires a reason).
     */
    public function recordClaimStatus(RecordClaimStatusRequest $request, InsuranceClaim $claim): JsonResponse
    {
        AccessCheck::scoped($this->claimInvoice($claim), write: true);

        [$claim, $transition] = $this->finance->recordClaimStatus(
            $claim,
            (string) $request->validated('status'),
            $request->validated('denialReason'),
            null,
            $request->user()?->getKey(),
        );

        $this->audit->record('insurance_claim.status', 'insurance_claim', $claim->getKey(), [
            'invoiceId' => $claim->invoice_id,
            'transition' => $transition,
            'denied' => $claim->status === InsuranceClaim::STATUS_DENIED,
        ], $request);

        return Envelope::success(data: self::presentClaim($claim), request: $request);
    }

    /**
     * POST claims/{claim}/settle — record the payer settlement
     * (partial | paid with settlementMinor; never more than billed).
     * Gated by insurance:settle (segregation of duties — the clerk who
     * builds/submits is not the only role that settles).
     */
    public function settleClaim(SettleClaimRequest $request, InsuranceClaim $claim): JsonResponse
    {
        AccessCheck::scoped($this->claimInvoice($claim), write: true);

        [$claim, $transition] = $this->finance->recordClaimStatus(
            $claim,
            (string) $request->validated('status'),
            null,
            (int) $request->validated('settlementMinor'),
            $request->user()?->getKey(),
        );

        $this->audit->record('insurance_claim.settled', 'insurance_claim', $claim->getKey(), [
            'invoiceId' => $claim->invoice_id,
            'transition' => $transition,
            'settlementMinor' => $claim->settlement_minor,
        ], $request);

        return Envelope::success(data: self::presentClaim($claim), request: $request);
    }

    private function claimInvoice(InsuranceClaim $claim): Invoice
    {
        $invoice = $claim->invoice()->first();

        if ($invoice === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Claim invoice not found.', 404);
        }

        return $invoice;
    }

    private function currentStaffId(TenantContext $context, string $facilityId): ?string
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', $facilityId)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        return $staff?->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentDeposit(Deposit $deposit): array
    {
        return [
            'id' => $deposit->getKey(),
            'patientId' => $deposit->patient_id,
            'amountMinor' => $deposit->amount_minor,
            'remainingMinor' => $deposit->remaining_minor,
            'status' => $deposit->status,
            'collectedAt' => $deposit->collected_at?->toIso8601String(),
            'lockVersion' => $deposit->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentSettlement(Settlement $settlement): array
    {
        return [
            'id' => $settlement->getKey(),
            'cashierId' => $settlement->cashier_id,
            'settlementDate' => $settlement->settlement_date->toDateString(),
            'expectedMinor' => $settlement->expected_minor,
            'actualMinor' => $settlement->actual_minor,
            'varianceMinor' => $settlement->variance_minor,
            'status' => $settlement->status,
            'reconciledAt' => $settlement->reconciled_at?->toIso8601String(),
            'notes' => $settlement->notes,
            'lockVersion' => $settlement->lock_version,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentClaim(InsuranceClaim $claim): array
    {
        return [
            'id' => $claim->getKey(),
            'claimNumber' => $claim->claim_number,
            'invoiceId' => $claim->invoice_id,
            'policyId' => $claim->policy_id,
            'payerId' => $claim->payer_id,
            'status' => $claim->status,
            'submittedAt' => $claim->submitted_at?->toIso8601String(),
            'denialReason' => $claim->denial_reason,
            'settlementMinor' => $claim->settlement_minor,
            'billedMinor' => $claim->billedTotalMinor(),
            'lockVersion' => $claim->lock_version,
            'lines' => $claim->relationLoaded('lines')
                ? $claim->lines->map(fn ($line): array => [
                    'id' => $line->getKey(),
                    'invoiceLineId' => $line->invoice_line_id,
                    'billedMinor' => $line->billed_minor,
                    'approvedMinor' => $line->approved_minor,
                    'status' => $line->status,
                ])->values()
                : [],
        ];
    }
}
