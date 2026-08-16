<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Deposit;
use App\Models\DepositAllocation;
use App\Models\InsuranceClaim;
use App\Models\InsuranceClaimLine;
use App\Models\InsurancePolicy;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Settlement;
use App\Models\Staff;
use App\Support\ErrorCodes;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 18 — remaining Billing and Finance (ROADMAP Phase 13,
 * PRODUCT_REQUIREMENTS §6.13–6.14, DATABASE.md §3.33–3.35).
 *
 *   Deposits:        collect (idempotent per key) → allocate against an
 *                    invoice (CAS on remaining_minor — exact allocation,
 *                    never more than the remaining balance).
 *   Settlements:     daily cashier reconciliation — expected (the day's
 *                    captured payments) vs actual; zero variance
 *                    reconciles, non-zero DISPUTES (never silently
 *                    absorbed); one row per (facility, cashier, day).
 *   Insurance claims: built from invoice truth (claim lines map exactly
 *                    to invoice lines — never fabricated), submit, track
 *                    status, record payer settlement. Every transition is
 *                    CAS-guarded on (status, lock_version).
 *
 * Money is integer minor units end to end. Financial rows are immutable
 * once posted; corrections are reversing entries, never edits. No payment
 * gateway is connected (INTEROPERABILITY.md §13 — planned, no provider
 * contract exists; nothing is faked here).
 */
final class FinanceService
{
    /**
     * Collect an advance payment (deposit) on a patient account. Idempotent
     * per idempotency key — a retry returns the existing deposit and holds
     * no new money.
     */
    public function collectDeposit(
        string $tenantId,
        string $facilityId,
        string $patientId,
        int $amountMinor,
        string $idempotencyKey,
        ?string $collectedBy = null,
    ): Deposit {
        return DB::transaction(function () use ($tenantId, $facilityId, $patientId, $amountMinor, $idempotencyKey, $collectedBy): Deposit {
            if ($amountMinor <= 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Deposit amount must be positive.', 422);
            }

            $existing = Deposit::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            return Deposit::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'amount_minor' => $amountMinor,
                'remaining_minor' => $amountMinor,
                'status' => Deposit::STATUS_ACTIVE,
                'idempotency_key' => $idempotencyKey,
                'collected_by' => $collectedBy,
                'collected_at' => now(),
                'lock_version' => 0,
                'created_by' => $collectedBy,
            ]);
        });
    }

    /**
     * Apply part of a deposit to an invoice — exact allocation. The deposit
     * and the invoice must belong to the same tenant, facility, and patient;
     * the allocation can never exceed the deposit's remaining balance, and
     * the unique (tenant, deposit, invoice) index makes a double allocation
     * structurally impossible. CAS on (status, remaining_minor, lock_version)
     * serializes concurrent allocations.
     */
    public function allocateDeposit(
        string $tenantId,
        string $facilityId,
        string $depositId,
        string $invoiceId,
        int $amountMinor,
        ?string $allocatedBy = null,
    ): DepositAllocation {
        return DB::transaction(function () use ($tenantId, $facilityId, $depositId, $invoiceId, $amountMinor, $allocatedBy): DepositAllocation {
            if ($amountMinor <= 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Allocation amount must be positive.', 422);
            }

            $deposit = Deposit::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $depositId)
                ->lockForUpdate()
                ->first();

            if ($deposit === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Deposit not found.', 404);
            }

            if ($deposit->status !== Deposit::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an active deposit can be allocated.', 409);
            }

            if ($amountMinor > $deposit->remaining_minor) {
                throw new ApiException(
                    ErrorCodes::VALIDATION_ERROR,
                    sprintf('Allocation of %d exceeds the deposit remaining balance of %d.', $amountMinor, $deposit->remaining_minor),
                    422,
                );
            }

            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->first();

            if ($invoice === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Invoice not found.', 404);
            }

            if ($invoice->facility_id !== $facilityId || $invoice->patient_id !== $deposit->patient_id) {
                throw new ApiException(ErrorCodes::INVALID_REQUEST, 'The invoice does not belong to this deposit\'s patient or facility.', 422);
            }

            if ($invoice->status === Invoice::STATUS_VOIDED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A voided invoice cannot receive a deposit allocation.', 409);
            }

            // One allocation per (deposit, invoice) — check BEFORE inserting
            // so a double allocation is a clean 409, not a raw unique-
            // violation 500 (the established billing pattern).
            $alreadyAllocated = DepositAllocation::query()
                ->where('tenant_id', $tenantId)
                ->where('deposit_id', $deposit->getKey())
                ->where('invoice_id', $invoice->getKey())
                ->exists();

            if ($alreadyAllocated) {
                throw new ApiException(ErrorCodes::CONFLICT, 'This deposit is already allocated to this invoice.', 409);
            }

            $allocation = DepositAllocation::query()->create([
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'deposit_id' => $deposit->getKey(),
                'invoice_id' => $invoice->getKey(),
                'amount_minor' => $amountMinor,
                'allocated_by' => $allocatedBy,
                'allocated_at' => now(),
                'created_by' => $allocatedBy,
            ]);

            // CAS on the deposit: status + remaining + lock_version — a
            // concurrent allocation to the same deposit affects zero rows.
            $newRemaining = $deposit->remaining_minor - $amountMinor;
            $newStatus = $newRemaining === 0 ? Deposit::STATUS_EXHAUSTED : Deposit::STATUS_ACTIVE;

            $affected = DB::table('deposits')
                ->where('tenant_id', $tenantId)
                ->where('id', $deposit->getKey())
                ->where('status', Deposit::STATUS_ACTIVE)
                ->where('remaining_minor', $deposit->remaining_minor)
                ->where('lock_version', $deposit->lock_version)
                ->update([
                    'remaining_minor' => $newRemaining,
                    'status' => $newStatus,
                    'lock_version' => $deposit->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                // The allocation row must not survive a failed CAS — the
                // whole transaction rolls back, so nothing persisted.
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The deposit was concurrently allocated; reload and retry.', 409);
            }

            return $allocation;
        });
    }

    /**
     * Reconcile a cashier's day. `expected_minor` is the day's captured
     * payments for that cashier (tenant + facility). The settlement row is
     * one per (facility, cashier, day) — the unique index serializes the
     * day. A zero variance reconciles; a non-zero variance DISPUTES (the
     * variance is never silently absorbed). A second reconcile of an
     * already-closed day affects zero rows and 409s (CAS on status +
     * lock_version).
     */
    public function reconcileSettlement(
        string $tenantId,
        string $facilityId,
        string $cashierId,
        string $settlementDate,
        int $actualMinor,
        ?string $reconciledBy = null,
        ?string $notes = null,
    ): Settlement {
        return DB::transaction(function () use ($tenantId, $facilityId, $cashierId, $settlementDate, $actualMinor, $reconciledBy, $notes): Settlement {
            if ($actualMinor < 0) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'Actual count cannot be negative.', 422);
            }

            // Payments record the capturing USER (payments.received_by); the
            // settlement is keyed by the STAFF cashier — the expected figure
            // is the cashier's user's captured payments for the day.
            $cashier = Staff::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $cashierId)
                ->first();

            if ($cashier === null || $cashier->user_id === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Cashier not found.', 404);
            }

            $expectedMinor = (int) Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('received_by', $cashier->user_id)
                ->where('status', Payment::STATUS_CAPTURED)
                ->whereDate('received_at', $settlementDate)
                ->sum('amount_minor');

            $settlement = Settlement::query()
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('cashier_id', $cashierId)
                ->where('settlement_date', $settlementDate)
                ->lockForUpdate()
                ->first();

            $varianceMinor = $actualMinor - $expectedMinor;
            $status = $varianceMinor === 0 ? Settlement::STATUS_RECONCILED : Settlement::STATUS_DISPUTED;

            if ($settlement === null) {
                return Settlement::query()->create([
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'cashier_id' => $cashierId,
                    'settlement_date' => $settlementDate,
                    'expected_minor' => $expectedMinor,
                    'actual_minor' => $actualMinor,
                    'variance_minor' => $varianceMinor,
                    'status' => $status,
                    'reconciled_by' => $reconciledBy,
                    'reconciled_at' => now(),
                    'notes' => $notes,
                    'lock_version' => 0,
                    'created_by' => $reconciledBy,
                ]);
            }

            if ($settlement->status !== Settlement::STATUS_OPEN) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This settlement day is already closed; reopen it to reconcile again.', 409);
            }

            $affected = DB::table('settlements')
                ->where('tenant_id', $tenantId)
                ->where('id', $settlement->getKey())
                ->where('status', Settlement::STATUS_OPEN)
                ->where('lock_version', $settlement->lock_version)
                ->update([
                    'expected_minor' => $expectedMinor,
                    'actual_minor' => $actualMinor,
                    'variance_minor' => $varianceMinor,
                    'status' => $status,
                    'reconciled_by' => $reconciledBy,
                    'reconciled_at' => now(),
                    'notes' => $notes,
                    'lock_version' => $settlement->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'This settlement day was reconciled concurrently; reload and retry.', 409);
            }

            return $settlement->refresh();
        });
    }

    /**
     * Build a claim (draft) from an invoice and the patient's policy: claim
     * lines map EXACTLY to the invoice's lines (billed = amount + tax,
     * frozen invoice truth) — never fabricated. The payer is the policy's
     * payer. One ACTIVE claim per (invoice, policy) — the partial unique
     * refuses a duplicate build; a DENIED claim may be re-created for
     * resubmission.
     */
    public function buildClaim(
        string $tenantId,
        string $invoiceId,
        string $policyId,
        ?string $createdBy = null,
    ): InsuranceClaim {
        return DB::transaction(function () use ($tenantId, $invoiceId, $policyId, $createdBy): InsuranceClaim {
            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $invoiceId)
                ->with('lines:id,tenant_id,invoice_id,amount_minor,tax_minor,line_no')
                ->first();

            if ($invoice === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Invoice not found.', 404);
            }

            $policy = InsurancePolicy::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $policyId)
                ->first();

            if ($policy === null) {
                throw new ApiException(ErrorCodes::NOT_FOUND, 'Insurance policy not found.', 404);
            }

            if ($policy->patient_id !== $invoice->patient_id) {
                throw new ApiException(ErrorCodes::INVALID_REQUEST, 'The policy does not belong to the invoice\'s patient.', 422);
            }

            if ($policy->status !== InsurancePolicy::STATUS_ACTIVE) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only an active policy can be claimed against.', 409);
            }

            // One claim per (invoice, policy), period — resubmission after
            // a denial reopens the SAME claim (reopenClaim), never creates
            // a duplicate row.
            $existing = InsuranceClaim::query()
                ->where('tenant_id', $tenantId)
                ->where('invoice_id', $invoiceId)
                ->where('policy_id', $policyId)
                ->exists();

            if ($existing) {
                throw new ApiException(ErrorCodes::CONFLICT, 'A claim already exists for this invoice and policy.', 409);
            }

            $lines = $invoice->lines ?? $invoice->lines()->get();

            if ($lines->isEmpty()) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The invoice has no lines to claim.', 409);
            }

            $claim = InsuranceClaim::query()->create([
                'tenant_id' => $tenantId,
                'policy_id' => $policyId,
                'invoice_id' => $invoiceId,
                'payer_id' => $policy->payer_id,
                'claim_number' => $this->nextClaimNumber($tenantId),
                'status' => InsuranceClaim::STATUS_DRAFT,
                'lock_version' => 0,
                'created_by' => $createdBy,
            ]);

            foreach ($lines as $line) {
                InsuranceClaimLine::query()->create([
                    'tenant_id' => $tenantId,
                    'claim_id' => $claim->getKey(),
                    'invoice_line_id' => $line->getKey(),
                    'billed_minor' => $line->amount_minor + $line->tax_minor,
                    'status' => InsuranceClaimLine::STATUS_PENDING,
                    'created_by' => $createdBy,
                ]);
            }

            return $claim->load('lines');
        });
    }

    /**
     * Submit a draft claim: draft → submitted (CAS). Requires at least one
     * line (always true by construction) and stamps submitted_at.
     */
    public function submitClaim(InsuranceClaim $claim, ?string $submittedBy = null): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $submittedBy): InsuranceClaim {
            $claim->refresh();

            if ($claim->status !== InsuranceClaim::STATUS_DRAFT) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'Only a draft claim can be submitted.', 409);
            }

            $affected = DB::table('claims')
                ->where('tenant_id', $claim->tenant_id)
                ->where('id', $claim->getKey())
                ->where('status', InsuranceClaim::STATUS_DRAFT)
                ->where('lock_version', $claim->lock_version)
                ->update([
                    'status' => InsuranceClaim::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'lock_version' => $claim->lock_version + 1,
                    'updated_by' => $submittedBy,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The claim was concurrently modified; reload and retry.', 409);
            }

            return $claim->refresh();
        });
    }

    /**
     * Reopen a DENIED claim for resubmission (denied → draft, CAS): the
     * denial is preserved in the audit trail, the claim lines stay unique
     * per invoice line, and the clerk can revise and submit again — no
     * fabricated duplicate claim lines (PRODUCT_REQUIREMENTS §6.14
     * "denials with reasons and resubmission").
     */
    public function reopenClaim(InsuranceClaim $claim, ?string $actorId = null): InsuranceClaim
    {
        return DB::transaction(function () use ($claim, $actorId): InsuranceClaim {
            $claim->refresh();

            if ($claim->status !== InsuranceClaim::STATUS_DENIED) {
                throw new ApiException(ErrorCodes::CONFLICT, 'Only a denied claim can be reopened for resubmission.', 409);
            }

            $affected = DB::table('claims')
                ->where('tenant_id', $claim->tenant_id)
                ->where('id', $claim->getKey())
                ->where('status', InsuranceClaim::STATUS_DENIED)
                ->where('lock_version', $claim->lock_version)
                ->update([
                    'status' => InsuranceClaim::STATUS_DRAFT,
                    'denial_reason' => null,
                    'lock_version' => $claim->lock_version + 1,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The claim was concurrently modified; reload and retry.', 409);
            }

            return $claim->refresh();
        });
    }

    /**
     * Record a payer status update on a submitted/pending claim
     * (submitted → pending | denied; pending → partial | paid | denied).
     * A denial requires a reason; partial/paid record the payer settlement
     * (never more than the claim's billed total — invoice truth).
     * `insurance:settle` gates the money-moving statuses; the service also
     * refuses a settlement on a claim that already has one.
     *
     * @return array{0: InsuranceClaim, 1: string} [claim, transition]
     */
    public function recordClaimStatus(
        InsuranceClaim $claim,
        string $status,
        ?string $denialReason = null,
        ?int $settlementMinor = null,
        ?string $actorId = null,
    ): array {
        return DB::transaction(function () use ($claim, $status, $denialReason, $settlementMinor, $actorId): array {
            $claim->refresh();
            $from = $claim->status;

            $allowed = match ($from) {
                InsuranceClaim::STATUS_SUBMITTED => [InsuranceClaim::STATUS_PENDING, InsuranceClaim::STATUS_DENIED],
                InsuranceClaim::STATUS_PENDING => [InsuranceClaim::STATUS_PARTIAL, InsuranceClaim::STATUS_PAID, InsuranceClaim::STATUS_DENIED],
                default => [],
            };

            if (! in_array($status, $allowed, true)) {
                throw new ApiException(
                    ErrorCodes::INVALID_REQUEST,
                    sprintf('Claim status cannot move from %s to %s.', $from, $status),
                    422,
                );
            }

            if ($status === InsuranceClaim::STATUS_DENIED && ($denialReason === null || trim($denialReason) === '')) {
                throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'A denial requires a reason.', 422);
            }

            if (in_array($status, [InsuranceClaim::STATUS_PARTIAL, InsuranceClaim::STATUS_PAID], true)) {
                if ($settlementMinor === null || $settlementMinor < 0) {
                    throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'A settlement amount is required for a paid or partial claim.', 422);
                }

                if ($claim->settlement_minor !== null) {
                    throw new ApiException(ErrorCodes::CONFLICT, 'This claim already has a recorded settlement.', 409);
                }

                if ($settlementMinor > $claim->billedTotalMinor()) {
                    throw new ApiException(
                        ErrorCodes::VALIDATION_ERROR,
                        'The settlement cannot exceed the claim\'s billed total.',
                        422,
                    );
                }
            }

            $affected = DB::table('claims')
                ->where('tenant_id', $claim->tenant_id)
                ->where('id', $claim->getKey())
                ->where('status', $from)
                ->where('lock_version', $claim->lock_version)
                ->update([
                    'status' => $status,
                    'denial_reason' => $status === InsuranceClaim::STATUS_DENIED ? $denialReason : null,
                    'settlement_minor' => in_array($status, [InsuranceClaim::STATUS_PARTIAL, InsuranceClaim::STATUS_PAID], true) ? $settlementMinor : null,
                    'lock_version' => $claim->lock_version + 1,
                    'updated_by' => $actorId,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ApiException(ErrorCodes::LOCK_CONFLICT, 'The claim was concurrently modified; reload and retry.', 409);
            }

            return [$claim->refresh(), $from.'->'.$status];
        });
    }

    private function nextClaimNumber(string $tenantId): string
    {
        do {
            $number = 'CLM-'.date('Ymd').'-'.random_int(10000, 99999);
        } while (InsuranceClaim::query()->where('tenant_id', $tenantId)->where('claim_number', $number)->exists());

        return $number;
    }
}
