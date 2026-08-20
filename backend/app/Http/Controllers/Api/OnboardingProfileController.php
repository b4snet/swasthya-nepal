<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RoleOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingProfileController extends Controller
{
    public function __construct(protected RoleOnboardingService $onboarding) {}

    public function steps(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'steps' => $this->onboarding->getStepsForUser($user),
            'current_step' => $user->onboarding_step,
            'complete' => $user->onboarding_complete,
            'landing_path' => $this->onboarding->landingPath($user),
        ]);
    }

    public function saveStep(Request $request, string $stepKey): JsonResponse
    {
        $user = $request->user();

        $errors = $this->onboarding->validateStep($user, $stepKey, $request->all());
        if ($errors) {
            return response()->json(['errors' => $errors], 422);
        }

        $user = $this->onboarding->saveStep($user, $stepKey, $request->all());

        return response()->json([
            'saved' => true,
            'current_step' => $user->onboarding_step,
            'profile' => $user->profile_data,
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->onboarding_complete) {
            return response()->json([
                'already_complete' => true,
                'landing_path' => $this->onboarding->landingPath($user),
            ]);
        }

        $user = $this->onboarding->completeOnboarding($user);

        return response()->json([
            'completed' => true,
            'landing_path' => $this->onboarding->landingPath($user),
        ]);
    }
}
