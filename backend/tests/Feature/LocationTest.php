<?php

use App\Models\AuditEvent;
use App\Models\Location;
use Tests\Support\Identity;

/**
 * Locations (DATABASE.md §3.9): non-bed physical places.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('creates, updates, and soft-deletes a location', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/locations', [
            'name' => 'Main Store',
            'code' => 'store-main',
            'facilityId' => $facility->getKey(),
            'type' => 'store',
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id');

    $location = Location::query()->findOrFail($response->json('data.id'));

    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/locations/'.$location->getKey(), ['status' => 'inactive'])
        ->assertOk()
        ->assertJsonPath('data.status', 'inactive');

    expect(AuditEvent::query()->where('action', 'location.updated')->exists())->toBeTrue();

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/locations/'.$location->getKey())
        ->assertStatus(204);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/locations/'.$location->getKey())
        ->assertStatus(404);
});

it('rejects an unknown location type', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/locations', [
            'name' => 'Warp Zone', 'code' => 'warp', 'facilityId' => $facility->getKey(), 'type' => 'warp_zone',
        ])
        ->assertStatus(422);
});
