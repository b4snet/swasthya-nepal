<?php

use App\Models\AuditEvent;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Identity;

/**
 * Authenticated password change (SECURITY.md §1–2, MASTER_RULES.md §7):
 * requires a valid session + current password, enforces the strength floor,
 * audits the change, and — because the contract states a password change
 * invalidates ALL outstanding tokens and sessions — revokes every token and
 * forces re-authentication.
 */
beforeEach(function (): void {
    seedIdentity();
});

function newPassword(): string
{
    return 'brand-new-strong-password-123';
}

function changePasswordPayload(string $current, string $new): array
{
    return [
        'current_password' => $current,
        'new_password' => $new,
        'new_password_confirmation' => $new,
    ];
}

it('requires an authenticated session to change a password', function () {
    $this->postJson('/api/v1/auth/password/change', changePasswordPayload(Identity::PASSWORD, newPassword()))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
});

it('rejects a new password that is too short or does not confirm', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);
    $token = Identity::tokenFor($user);

    $this->withToken($token)->postJson('/api/v1/auth/password/change', [
        'current_password' => Identity::PASSWORD,
        'new_password' => 'short',
        'new_password_confirmation' => 'short',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

    $this->withToken($token)->postJson('/api/v1/auth/password/change', [
        'current_password' => Identity::PASSWORD,
        'new_password' => newPassword(),
        'new_password_confirmation' => 'mismatch-confirmation',
    ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
});

it('rejects a password change when the current password is wrong', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);
    $token = Identity::tokenFor($user);

    $this->withToken($token)->postJson('/api/v1/auth/password/change', changePasswordPayload('wrong-current-password', newPassword()))
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    expect(AuditEvent::query()
        ->where('action', 'auth.password_change_failed')
        ->where('actor_id', $user->getKey())
        ->count())->toBe(1)
        ->and(Hash::check(Identity::PASSWORD, $user->fresh()->password_hash))->toBeTrue();
});

it('rejects reusing the current password as the new password', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);
    $token = Identity::tokenFor($user);

    $this->withToken($token)->postJson('/api/v1/auth/password/change', changePasswordPayload(Identity::PASSWORD, Identity::PASSWORD))
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'INVALID_REQUEST');

    expect(AuditEvent::query()
        ->where('action', 'auth.password_change_failed')
        ->count())->toBe(1);
});

it('changes the password, hashes the new credential, and audits the event', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);
    $token = Identity::tokenFor($user);

    expect($user->fresh()->password_changed_at)->toBeNull();

    $this->withToken($token)->postJson('/api/v1/auth/password/change', changePasswordPayload(Identity::PASSWORD, newPassword()))
        ->assertStatus(204);

    $reloaded = $user->fresh();
    expect(Hash::check(newPassword(), $reloaded->password_hash))->toBeTrue()
        ->and(Hash::check(Identity::PASSWORD, $reloaded->password_hash))->toBeFalse()
        ->and($reloaded->password_changed_at)->not->toBeNull();

    expect(AuditEvent::query()->where('action', 'auth.password_changed')->where('actor_id', $user->getKey())->count())->toBe(1);
});

it('revokes the current access token and every refresh token on password change', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'pw@one.test',
        'password' => Identity::PASSWORD,
    ]);
    $token = $login->json('data.accessToken');

    expect(RefreshToken::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->count())->toBe(1);

    $this->withToken($token)->postJson('/api/v1/auth/password/change', changePasswordPayload(Identity::PASSWORD, newPassword()))
        ->assertStatus(204);

    // SECURITY.md §2/§4: password change invalidates ALL outstanding tokens
    // and sessions — the current access token and every refresh token are gone.
    expect(RefreshToken::query()->where('user_id', $user->getKey())->whereNull('revoked_at')->count())->toBe(0);

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
});

it('requires re-authentication with the new password after a change', function () {
    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);

    $login = $this->postJson('/api/v1/auth/login', [
        'email' => 'pw@one.test',
        'password' => Identity::PASSWORD,
    ]);
    $token = $login->json('data.accessToken');

    $this->withToken($token)->postJson('/api/v1/auth/password/change', changePasswordPayload(Identity::PASSWORD, newPassword()))
        ->assertStatus(204);

    // The old password no longer authenticates.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'pw@one.test',
        'password' => Identity::PASSWORD,
    ])->assertStatus(401);

    // The new password does.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'pw@one.test',
        'password' => newPassword(),
    ])->assertOk()
        ->assertJsonStructure(['data' => ['accessToken', 'refreshToken']]);
});
