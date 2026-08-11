<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queue token issuance (DATABASE.md §3.15: "token sequence uniqueness
 * handled by the queue — row-locked issuance"). The counter lives in
 * token_counters keyed by (tenant, facility, provider, date); check-in
 * issues the next token by locking the counter row — parallel check-ins
 * cannot mint the same number.
 */
final class TokenIssuer
{
    public function issue(string $tenantId, string $facilityId, string $providerStaffId, string $date): int
    {
        return DB::transaction(function () use ($tenantId, $facilityId, $providerStaffId, $date): int {
            DB::table('token_counters')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'provider_staff_id' => $providerStaffId,
                'queue_date' => $date,
                'last_token' => 0,
            ]);

            $counter = DB::table('token_counters')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('provider_staff_id', $providerStaffId)
                ->where('queue_date', $date)
                ->lockForUpdate()
                ->first();

            $token = (int) $counter->last_token + 1;

            DB::table('token_counters')
                ->where('id', $counter->id)
                ->update(['last_token' => $token]);

            return $token;
        });
    }
}
