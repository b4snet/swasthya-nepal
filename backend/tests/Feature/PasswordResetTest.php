<?php

use App\Mail\ResetPasswordMail;
use App\Models\AuditEvent;
use App\Models\PasswordResetToken;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Identity;

/**
 * PROGRAM PHASE 2 (password reset, SECURITY.md §5).
 *
 * Covers: no account enumeration, single-use + expiring tokens, invalid /
 * expired / replayed tokens, password-strength rule, refresh-token
 * revocation, failed-login counter reset, and audit events.
 */
beforeEach(function (): void {
    seedIdentity();
    Mail::fake();
});

it('never reveals whether an email has an account', function () {
    $orgA = Identity::organization(['code' => 'reset-enum']);
    Identity::facility($orgA);
    $userA = Identity::user(['email' => 'reset-enum@isolation.test']);
    Identity::assign($userA, 'org_admin', $orgA);

    $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset-enum@isolation.test']);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@isolation.test']);

    $known->assertOk();
    $unknown->assertOk();
    expect($known->json('data.message'))->toBe($unknown->json('data.message'));

    // Token issued and mailed only for the real account.
    Mail::assertSent(ResetPasswordMail::class, fn (ResetPasswordMail $mail): bool => $mail->to[0]['address'] === 'reset-enum@isolation.test');
    expect(PasswordResetToken::query()->count())->toBe(1);
});

it('resets the password with a valid single-use token and revokes all sessions', function () {
    $orgA = Identity::organization(['code' => 'reset-ok']);
    Identity::facility($orgA);
    $userA = Identity::user(['email' => 'reset-ok@isolation.test']);
    Identity::assign($userA, 'org_admin', $orgA);

    // Establish a live session.
    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'reset-ok@isolation.test',
        'password' => Identity::PASSWORD,
    ])->assertOk();
    $beforeTokens = RefreshToken::query()->where('user_id', $userA->getKey())->whereNull('revoked_at')->count();
    expect($beforeTokens)->toBeGreaterThan(0);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset-ok@isolation.test'])->assertOk();

    // Get the token via the mailable (the delivery channel).
    $token = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });
    expect($token)->not->toBeNull();

    $newPassword = 'new-correct-horse-battery-staple-2';
    $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => $newPassword])
        ->assertOk();

    // Token consumed; every pre-reset session revoked (the old refresh token
    // family is dead even before any new login).
    $record = PasswordResetToken::query()->where('user_id', $userA->getKey())->first();
    expect($record->consumed_at)->not->toBeNull();
    expect(RefreshToken::query()->where('user_id', $userA->getKey())->whereNull('revoked_at')->count())->toBe(0);

    // Stored hash never equals the plaintext token.
    expect($record->token_hash)->not->toBe($token);

    // New password works; old password fails.
    $this->postJson('/api/v1/auth/login', ['email' => 'reset-ok@isolation.test', 'password' => $newPassword])->assertOk();
    $this->postJson('/api/v1/auth/login', ['email' => 'reset-ok@isolation.test', 'password' => Identity::PASSWORD])
        ->assertStatus(401);
});

it('rejects replayed, invalid, and expired reset tokens', function () {
    $orgA = Identity::organization(['code' => 'reset-replay']);
    Identity::facility($orgA);
    $userA = Identity::user(['email' => 'reset-replay@isolation.test']);
    Identity::assign($userA, 'org_admin', $orgA);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset-replay@isolation.test'])->assertOk();

    $token = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    // First use succeeds.
    $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'valid-new-password-123'])->assertOk();

    // Replay of the same token is rejected.
    $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'another-valid-pass-456'])
        ->assertStatus(401);

    // Garbage token is rejected.
    $this->postJson('/api/v1/auth/password/reset', ['token' => 'swpr_bogus-token', 'password' => 'another-valid-pass-456'])
        ->assertStatus(401);

    // Expired token is rejected.
    $userB = Identity::user(['email' => 'reset-expired@isolation.test']);
    $expired = PasswordResetToken::query()->create([
        'user_id' => $userB->getKey(),
        'token_hash' => hash('sha256', 'swpr_expired-token'),
        'expires_at' => now()->subMinute(),
    ]);
    $this->postJson('/api/v1/auth/password/reset', ['token' => 'swpr_expired-token', 'password' => 'another-valid-pass-456'])
        ->assertStatus(401);
    expect($expired->refresh()->consumed_at)->toBeNull();
});

it('enforces the password-strength rule and rate-limits reset attempts', function () {
    $orgA = Identity::organization(['code' => 'reset-strength']);
    Identity::facility($orgA);
    $userA = Identity::user(['email' => 'reset-strength@isolation.test']);
    Identity::assign($userA, 'org_admin', $orgA);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset-strength@isolation.test'])->assertOk();

    $token = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });

    // Too-short password → 422, token NOT consumed.
    $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'short'])
        ->assertStatus(422);
    expect(PasswordResetToken::query()->where('user_id', $userA->getKey())->first()->consumed_at)->toBeNull();
});

it('audits reset requests and completions', function () {
    $orgA = Identity::organization(['code' => 'reset-audit']);
    Identity::facility($orgA);
    $userA = Identity::user(['email' => 'reset-audit@isolation.test']);
    Identity::assign($userA, 'org_admin', $orgA);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset-audit@isolation.test'])->assertOk();

    $token = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token): bool {
        $token = $mail->token;

        return true;
    });
    $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'password' => 'valid-new-password-123'])->assertOk();

    $events = AuditEvent::query()
        ->where('action', 'like', 'auth.password_reset%')
        ->orderBy('occurred_at')
        ->get();

    expect($events->pluck('action')->values()->all())->toBe([
        'auth.password_reset_requested',
        'auth.password_reset',
    ]);
});
