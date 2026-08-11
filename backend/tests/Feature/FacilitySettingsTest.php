<?php

use App\Models\AuditEvent;
use App\Models\Facility;
use App\Models\FacilitySetting;
use Tests\Support\Identity;

/**
 * Facility configuration as data (PRODUCT_REQUIREMENTS §5.5): versioned,
 * audited, facility-scoped.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('upserts settings with versioning and an audit event per change', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->putJson('/api/v1/facilities/'.$facility->getKey().'/settings', [
            'settings' => ['queue.default_slot_minutes' => 10, 'billing.vat_bps' => 1300],
        ])
        ->assertOk();

    $setting = FacilitySetting::query()->where('key', 'queue.default_slot_minutes')->firstOrFail();
    expect($setting->version)->toBe(1)
        ->and($setting->value)->toBe(10);

    // Second write bumps the version and records old → new. The setting keys
    // contain dots, so the response is asserted directly rather than via a
    // dot-notation JSON path.
    $response = $this->withToken(Identity::tokenFor($admin))
        ->putJson('/api/v1/facilities/'.$facility->getKey().'/settings', [
            'settings' => ['queue.default_slot_minutes' => 15],
        ])
        ->assertOk();

    expect($response->json('data')['queue.default_slot_minutes']['version'])->toBe(2);

    // Same occurred_at within a test — tie-break on the uuidv7 id, exactly
    // like the audit chain itself (AuditLogger).
    $event = AuditEvent::query()
        ->where('action', 'facility.settings.updated')
        ->orderByDesc('occurred_at')
        ->orderByDesc('id')
        ->firstOrFail();
    expect($event->payload['changes']['queue.default_slot_minutes'])->toBe([10, 15])
        ->and($event->tenant_id)->toBe($org->getKey())
        ->and($event->facility_id)->toBe($facility->getKey());
});

it('deletes a setting with an audit event and the last value', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    FacilitySetting::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'key' => 'queue.default_slot_minutes',
        'value' => ['minutes' => 10],
    ]);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/facilities/'.$facility->getKey().'/settings/queue.default_slot_minutes')
        ->assertStatus(204);

    expect(FacilitySetting::query()->where('key', 'queue.default_slot_minutes')->exists())->toBeFalse();

    $event = AuditEvent::query()->where('action', 'facility.settings.deleted')->firstOrFail();
    expect($event->payload['key'])->toBe('queue.default_slot_minutes')
        ->and($event->payload['lastValue'])->toBe(['minutes' => 10]);

    // Deleting again → 404.
    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/facilities/'.$facility->getKey().'/settings/queue.default_slot_minutes')
        ->assertStatus(404);
});

it('rejects invalid setting keys', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->putJson('/api/v1/facilities/'.$facility->getKey().'/settings', [
            'settings' => ['Bad Key!' => true],
        ])
        ->assertStatus(422);
});

it('scopes settings to the facility — other facilities are invisible and unwritable', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org, ['code' => 'fac-a']);
    $facilityB = Identity::facility($org, ['code' => 'fac-b']);
    FacilitySetting::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facilityB->getKey(), 'key' => 'secret.setting']);

    $admin = Identity::user();
    Identity::assign($admin, 'hospital_admin', $org, $facilityA);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/facilities/'.$facilityB->getKey().'/settings')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($admin))
        ->putJson('/api/v1/facilities/'.$facilityB->getKey().'/settings', ['settings' => ['x1' => 1]])
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/facilities/'.$facilityA->getKey().'/settings')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('lets a branch manager view settings but not change them', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $manager = Identity::user();
    Identity::assign($manager, 'branch_manager', $org, $facility);

    $this->withToken(Identity::tokenFor($manager))
        ->getJson('/api/v1/facilities/'.$facility->getKey().'/settings')
        ->assertOk();

    $this->withToken(Identity::tokenFor($manager))
        ->putJson('/api/v1/facilities/'.$facility->getKey().'/settings', ['settings' => ['x' => 1]])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'SCOPE_DENIED');
});
