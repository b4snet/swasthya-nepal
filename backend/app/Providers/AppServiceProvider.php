<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters by route class (API_CONTRACTS.md §15, SECURITY.md §17).
     *
     * Per-IP by default; the per-account dimension joins when authentication
     * lands (Phases 3–4). Limits are Redis-backed in production so they
     * survive instance scaling; tunable via SWASTHYA_RATE_LIMIT_* env vars
     * (config/swasthya.php). Bypassing rate limits in any environment is
     * prohibited (SECURITY.md §17).
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(config('swasthya.rate_limits.api'))->by($request->ip());
        });

        RateLimiter::for('auth', function (Request $request): Limit {
            return Limit::perMinute(config('swasthya.rate_limits.auth'))->by($request->ip());
        });

        RateLimiter::for('writes', function (Request $request): Limit {
            return Limit::perMinute(config('swasthya.rate_limits.writes'))->by($request->ip());
        });
    }
}
