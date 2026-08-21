<?php

namespace App\Integrations\Accounting;

/**
 * Null accounting provider.
 *
 * Used when no external accounting system is configured.
 * All operations throw AccountingProviderException.
 *
 * Classification: EXTERNAL DEPENDENCY
 */
class NullProvider implements AccountingProvider
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function testConnection(): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function exportInvoice(array $invoice): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function exportPayment(array $payment): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function exportRefund(array $refund): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function exportExpense(array $expense): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function importChartOfAccounts(): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function getAccountBalance(string $accountCode): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function getTrialBalance(): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function getOutstandingInvoices(string $asOfDate): array
    {
        throw AccountingProviderException::notConfigured();
    }

    public function acknowledgeExport(string $externalId, string $exportedAt): void
    {
        throw AccountingProviderException::notConfigured();
    }
}
