<?php

namespace App\Integrations\Accounting;

/**
 * Accounting integration adapter interface.
 *
 * External accounting providers (Tally, QuickBooks, Xero, etc.) implement
 * this interface. The hospital's accounting system is NOT built into
 * SWASTHYA — it is an external system connected through this boundary.
 *
 * Classification: EXTERNAL DEPENDENCY
 *
 * No fake accounting logic lives here. Every method maps to an actual
 * external API call. If no provider is configured, all methods should
 * throw AccountingProviderException.
 */
interface AccountingProvider
{
    /**
     * Check whether the provider is configured and reachable.
     */
    public function isConfigured(): bool;

    /**
     * Test connectivity to the external accounting system.
     *
     * @return array{status: string, message: string, latencyMs: int}
     */
    public function testConnection(): array;

    /**
     * Export a finalized invoice to the external accounting system.
     *
     * @param array{
     *     invoiceId: string,
     *     invoiceNumber: string,
     *     patientId: string,
     *     facilityId: string,
     *     issuedAt: string,
     *     dueAt: string|null,
     *     currency: string,
     *     subtotalMinor: int,
     *     taxMinor: int,
     *     totalMinor: int,
     *     lines: array<array{
     *         description: string,
     *         quantity: float,
     *         unitPriceMinor: int,
     *         totalMinor: int,
     *         accountCode: string|null,
     *     }>,
     * } $invoice
     * @return array{externalId: string, exportedAt: string}
     */
    public function exportInvoice(array $invoice): array;

    /**
     * Export a payment record to the external accounting system.
     *
     * @param array{
     *     paymentId: string,
     *     invoiceId: string,
     *     method: string,
     *     amountMinor: int,
     *     currency: string,
     *     receivedAt: string,
     *     reference: string|null,
     * } $payment
     * @return array{externalId: string, exportedAt: string}
     */
    public function exportPayment(array $payment): array;

    /**
     * Export a refund to the external accounting system.
     *
     * @param array{
     *     refundId: string,
     *     invoiceId: string,
     *     amountMinor: int,
     *     currency: string,
     *     reason: string,
     *     refundedAt: string,
     * } $refund
     * @return array{externalId: string, exportedAt: string}
     */
    public function exportRefund(array $refund): array;

    /**
     * Export an expense to the external accounting system.
     *
     * @param array{
     *     expenseId: string,
     *     category: string,
     *     description: string,
     *     amountMinor: int,
     *     currency: string,
     *     expenseDate: string,
     *     vendorId: string|null,
     *     costCenter: string|null,
     *     accountCode: string|null,
     * } $expense
     * @return array{externalId: string, exportedAt: string}
     */
    public function exportExpense(array $expense): array;

    /**
     * Import chart of accounts from the external system.
     *
     * @return array<array{code: string, name: string, type: string, parentCode: string|null}>
     */
    public function importChartOfAccounts(): array;

    /**
     * Fetch the current balance for a given account code.
     *
     * @return array{accountCode: string, balanceMinor: int, currency: string, asOf: string}
     */
    public function getAccountBalance(string $accountCode): array;

    /**
     * Fetch trial balance for the current period.
     *
     * @return array<array{accountCode: string, accountName: string, debitMinor: int, creditMinor: int}>
     */
    public function getTrialBalance(): array;

    /**
     * Fetch outstanding invoices from the accounting system for reconciliation.
     *
     *
     * @return array<array{externalId: string, invoiceNumber: string, totalMinor: int, outstandingMinor: int, dueDate: string|null}>
     */
    public function getOutstandingInvoices(string $asOfDate): array;

    /**
     * Acknowledge a successful export sync.
     */
    public function acknowledgeExport(string $externalId, string $exportedAt): void;
}
