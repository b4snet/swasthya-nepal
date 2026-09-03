<?php

use App\Services\BreachedPasswordService;
use Illuminate\Support\Facades\Http;
use Tests\Support\Identity;

/**
 * k-anonymity breached-password checking (SECURITY.md §2, §20): the service
 * sends only a 5-hex-char SHA-1 prefix to HIBP and matches suffixes locally,
 * with a configurable fail-open/fail-closed policy. Integrations: password
 * change (AuthController) and user provisioning (UserController). The default
 * test suite runs with checking disabled (phpunit.xml); these tests opt in
 * and fake HTTP so no external call is ever made.
 */
function sha1Suffix(string $password): string
{
    return substr(strtoupper(hash('sha1', $password)), 5);
}

function sha1Prefix(string $password): string
{
    return substr(strtoupper(hash('sha1', $password)), 0, 5);
}

beforeEach(function (): void {
    seedIdentity();
    Http::preventStrayRequests();
});

it('sends only the k-anonymity prefix and reports a breached password', function () {
    $password = 'correct-horse-battery-staple';

    Http::fake(['api.pwnedpasswords.com/*' => Http::response(sha1Suffix($password).':3866629'."\n")]);

    $service = app(BreachedPasswordService::class);

    expect($service->isBreached($password))->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/range/'.sha1Prefix($password)));
});

it('reports not-breached when the suffix is absent from the provider response', function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000000000:1\n")]);

    expect(app(BreachedPasswordService::class)->isBreached('a-fresh-unlikely-password'))->toBeFalse();
});

it('is a no-op for an empty password', function () {
    Http::fake();

    expect(app(BreachedPasswordService::class)->isBreached(''))->toBeFalse();
});

it('fails open on a provider error by default so users are never locked out', function () {
    config()->set('swasthya.auth.breach.fail_policy', 'fail-open');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 500)]);

    expect(app(BreachedPasswordService::class)->isBreached('whatever-password'))->toBeFalse();
});

it('fails closed when the configured policy demands it', function () {
    config()->set('swasthya.auth.breach.fail_policy', 'fail-closed');
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 500)]);

    expect(app(BreachedPasswordService::class)->isBreached('whatever-password'))->toBeTrue();
});

it('rejects a breached password on password change', function () {
    config()->set('swasthya.auth.breach.enabled', true);

    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);
    $token = Identity::tokenFor($user);

    $newPassword = 'pwned-pwned-password-123';
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(sha1Suffix($newPassword).':3866629'."\n")]);

    $this->withToken($token)->postJson('/api/v1/auth/password/change', [
        'current_password' => Identity::PASSWORD,
        'new_password' => $newPassword,
        'new_password_confirmation' => $newPassword,
    ])->assertStatus(422)->assertJsonPath('error.code', 'BREACHED_PASSWORD');

    // The credential is unchanged; the event is audited.
    expect(\App\Models\AuditEvent::query()->where('action', 'auth.password_change_failed')->count())->toBe(1)
        ->and(\Illuminate\Support\Facades\Hash::check(Identity::PASSWORD, $user->fresh()->password_hash))->toBeTrue();
});

it('allows a non-breached password on change', function () {
    config()->set('swasthya.auth.breach.enabled', true);

    $org = Identity::organization();
    $user = Identity::user(['email' => 'pw@one.test']);
    Identity::assign($user, 'org_admin', $org);
    $token = Identity::tokenFor($user);

    $newPassword = 'brand-new-strong-password-123';
    Http::fake(['api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000000000:1\n")]);

    $this->withToken($token)->postJson('/api/v1/auth/password/change', [
        'current_password' => Identity::PASSWORD,
        'new_password' => $newPassword,
        'new_password_confirmation' => $newPassword,
    ])->assertStatus(204);
});

it('rejects a breached password when provisioning a user', function () {
    config()->set('swasthya.auth.breach.enabled', true);

    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $password = 'breached-provision-pass';
    Http::fake(['api.pwnedpasswords.com/*' => Http::response(sha1Suffix($password).':3866629'."\n")]);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/users', [
            'email' => 'nurse@two.test',
            'password' => $password,
            'roleCode' => 'nurse',
            'facilityId' => $facility->getKey(),
        ])->assertStatus(422)->assertJsonPath('error.code', 'BREACHED_PASSWORD');

    expect(\App\Models\User::query()->where('email', 'nurse@two.test')->exists())->toBeFalse();
});