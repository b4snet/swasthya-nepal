<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * MRN issuance (PRODUCT_REQUIREMENTS §6.1, DATABASE.md §3.11).
 *
 * MRNs are sequential per tenant (`MRN-%06d`) and NEVER reused. The sequence
 * lives in `mrn_counters`; issuance is an atomic increment inside the same
 * transaction that creates the patient, serialized by the counter row lock —
 * parallel registrations (morning OPD rush) cannot mint the same number.
 */
final class MrnIssuer
{
    public function issue(string $tenantId): string
    {
        return DB::transaction(function () use ($tenantId): string {
            // Insert-or-ignore survives the race when two registrations mint
            // the first number for a brand-new tenant.
            DB::table('mrn_counters')->insertOrIgnore(['tenant_id' => $tenantId, 'last_value' => 0]);

            // Serialize concurrent issuers on the counter row.
            $counter = DB::table('mrn_counters')
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            $value = (int) $counter->last_value + 1;

            DB::table('mrn_counters')
                ->where('tenant_id', $tenantId)
                ->update(['last_value' => $value]);

            return sprintf('MRN-%06d', $value);
        });
    }
}
