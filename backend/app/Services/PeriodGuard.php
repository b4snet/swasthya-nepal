<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\FinancialPeriod;
use App\Support\ErrorCodes;
use App\Support\TenantContext;

/**
 * Fiscal period guard — prevents financial transactions against
 * closed or locked periods.
 *
 * Accounting rule: only OPEN periods allow new charges, invoices,
 * and payments. Closed periods are read-only for historical reporting.
 * Locked periods are irreversible — they cannot be reopened.
 */
final class PeriodGuard
{
    /**
     * Assert that the period covering the given date and facility is open.
     * Throws an ApiException if the period is closed or locked.
     *
     * @throws ApiException
     */
    public function assertOpen(?string $facilityId = null, ?string $date = null): void
    {
        $date = $date ?? now()->toDateString();
        $context = TenantContext::current();

        $period = FinancialPeriod::query()
            ->where('tenant_id', $context->tenantId())
            ->when($facilityId, fn ($q) => $q->where('facility_id', $facilityId))
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->first();

        // No period configured — allow (periods are optional)
        if ($period === null) {
            return;
        }

        $status = $period->period_status ?? $period->status;

        if ($status === 'locked') {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This fiscal period is locked. No financial transactions are allowed against a locked period.',
                409,
            );
        }

        if ($status === 'closed' || $status === 'closing') {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This fiscal period is closed. Only open periods allow new financial transactions.',
                409,
            );
        }
    }

    /**
     * Check if a period is open without throwing. Returns true if open
     * or if no period covers the date.
     */
    public function isOpen(?string $facilityId = null, ?string $date = null): bool
    {
        try {
            $this->assertOpen($facilityId, $date);

            return true;
        } catch (ApiException) {
            return false;
        }
    }
}
