<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Services\PortalActivationService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Portal activation endpoints (Phase 82): invitation send, token verify,
 * account activation with self-service password, and password reset.
 *
 * Public endpoints (no auth): verifyToken, activate, requestPasswordReset
 * Staff endpoints (auth required): sendInvitation
 */
final class PortalActivationController extends Controller
{
    public function __construct(
        private readonly PortalActivationService $activation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /patients/{patient}/portal/invite — staff sends invitation.
     */
    public function sendInvitation(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();

        $result = $this->activation->sendInvitation(
            (string) $context->tenantId(),
            (string) $context->facilityId() ?? $patient->facility_id,
            $patient->getKey(),
            (string) $context->user?->getKey(),
            $request->validated('email'),
            $request->validated('phone'),
        );

        return Envelope::success(data: $result, status: 201, request: $request);
    }

    /**
     * GET /portal/activate/{token} — verify invitation token (public).
     */
    public function verifyToken(Request $request, string $token): JsonResponse
    {
        $result = $this->activation->verifyToken($token);

        return Envelope::success(data: $result, request: $request);
    }

    /**
     * POST /portal/activate/{token} — activate account with password (public).
     */
    public function activate(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ]);

        $result = $this->activation->activate(
            $token,
            $validated['password'],
            $request->ip(),
            $request->userAgent(),
        );

        return Envelope::success(data: [
            'token' => $result['token'],
            'session' => [
                'id' => $result['session']->getKey(),
                'expiresAt' => $result['session']->expires_at?->toIso8601String(),
            ],
        ], status: 201, request: $request);
    }

    /**
     * POST /portal/forgot-password — request password reset (public).
     */
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organizationCode' => ['required', 'string', 'max:100'],
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        $result = $this->activation->requestPasswordReset(
            $validated['organizationCode'],
            $validated['identifier'],
        );

        return Envelope::success(data: $result, request: $request);
    }
}
