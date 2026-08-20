<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceOnboarding
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->requiresOnboarding()) {
            return response()->json([
                'error' => 'onboarding_required',
                'message' => 'Complete your profile to continue.',
                'onboarding_step' => $user->onboarding_step,
                'redirect' => '/api/v1/onboarding/profile/steps',
            ], 403);
        }

        return $next($request);
    }
}
