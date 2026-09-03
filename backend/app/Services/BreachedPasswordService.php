<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * k-anonymity breached-password checking against the Have I Been Pwned
 * Pwned Passwords range endpoint (SECURITY.md §2, §20).
 *
 * Only the first 5 hex characters of the SHA-1 of a candidate password are
 * ever sent to the provider; the remaining suffixes are compared locally.
 * The provider's Add-Padding flag is used so a query for a rare prefix is
 * indistinguishable from a common one.
 *
 * Failing policy is configurable (swasthya.auth.breach.fail_policy):
 *   - fail-open (default): a network/provider failure is treated as "not
 *     breached" so an outage can never lock a user out of setting a password.
 *   - fail-closed: a failure is treated as "breached" (block the password)
 *     for environments that prefer to err toward rejecting.
 */
final class BreachedPasswordService
{
    public function enabled(): bool
    {
        return (bool) config('swasthya.auth.breach.enabled', false);
    }

    public function isBreached(string $password): bool
    {
        if ($password === '') {
            return false;
        }

        $hash = strtoupper(hash('sha1', $password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        $base = rtrim((string) config('swasthya.auth.breach.api_url', 'https://api.pwnedpasswords.com'), '/');

        try {
            $response = Http::timeout((float) config('swasthya.auth.breach.timeout_seconds', 3))
                ->withHeaders(['Add-Padding' => 'true'])
                ->get($base.'/range/'.$prefix);

            if ($response->failed()) {
                return $this->handleFailure();
            }
        } catch (ConnectionException) {
            return $this->handleFailure();
        }

        // Body is lines of "<suffix>:<count>"; only our exact suffix matches.
        $found = collect(explode("\n", (string) $response->body()))
            ->map(fn (string $line): string => trim(explode(':', $line, 2)[0] ?? ''))
            ->contains($suffix);

        return $found;
    }

    /**
     * Map a provider/network failure to the configured fail policy.
     */
    private function handleFailure(): bool
    {
        return config('swasthya.auth.breach.fail_policy', 'fail-open') === 'fail-closed';
    }
}
