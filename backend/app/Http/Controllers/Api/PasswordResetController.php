<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Services\PasswordResetService;
use App\Support\AuditLogger;
use App\Support\Envelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

/**
 * PROGRAM PHASE 2 (password reset, SECURITY.md §5).
 *
 *  - forgot: accepts an email, ALWAYS returns the same generic success —
 *    the response does not reveal whether the account exists (no account
 *    enumeration). When the account exists, a single-use token is issued
 *    and mailed; the token is stored only as a SHA-256 hash.
 *  - reset: validates the token (single-use, 15-min expiry), sets the new
 *    password (min:12, same rule as initial provisioning), revokes every
 *    refresh-token family, and clears the failed-login counter.
 *  - Both endpoints sit behind throttle:auth (per-IP) plus the service's
 *    per-account failure limiter on reset.
 */
final class PasswordResetController extends Controller
{
    public function __construct(
        private readonly PasswordResetService $resets,
        private readonly AuditLogger $audit,
    ) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->validated('email')));

        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($user !== null && $user->status !== User::STATUS_DISABLED) {
            $token = $this->resets->issue($user, $request->ip(), $request->userAgent());

            // Delivery channel: the configured mailer (log driver in local).
            Mail::to($user->email)->send(new ResetPasswordMail($token));

            $this->audit->record('auth.password_reset_requested', 'user', $user->getKey(), [], $request, $user);
        }

        // Generic response regardless of whether the account exists.
        return Envelope::success(data: [
            'message' => 'If an account exists for that email, a password-reset token has been sent.',
        ], request: $request);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $token = (string) $request->validated('token');

        try {
            $user = $this->resets->consume(
                $token,
                (string) $request->validated('password'),
                $request->ip(),
                $request->userAgent(),
            );
        } catch (ApiException $exception) {
            $this->audit->record('auth.password_reset_failed', 'user', null, ['code' => $exception->errorCode], $request);
            throw $exception;
        }

        $this->audit->record('auth.password_reset', 'user', $user->getKey(), [], $request, $user);

        return Envelope::success(data: [
            'message' => 'Your password has been reset. You can now sign in.',
        ], request: $request);
    }
}
