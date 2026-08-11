<?php

use App\Models\AuditEvent;
use App\Models\Service;
use Tests\Support\Identity;

/**
 * Hospital services catalog — the offerings schedules and appointment
 * booking will reference.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('creates, updates, and soft-deletes a service', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/services', [
            'name' => 'OPD Consultation',
            'code' => 'opd-consult',
            'facilityId' => $facility->getKey(),
            'serviceType' => 'opd_consultation',
            'defaultDurationMinutes' => 15,
            'defaultChargeMinor' => 100000,
            'currency' => 'NPR',
        ])
        ->assertCreated()
        ->assertHeader('X-Audit-Event-Id')
        ->assertJsonPath('data.defaultChargeMinor', 100000);

    $service = Service::query()->findOrFail($response->json('data.id'));

    // Rate changes are audited — financial truth for future billing.
    $this->withToken(Identity::tokenFor($admin))
        ->patchJson('/api/v1/services/'.$service->getKey(), ['defaultChargeMinor' => 120000])
        ->assertOk();

    $event = AuditEvent::query()->where('action', 'service.updated')->firstOrFail();
    expect($event->payload['changes']['default_charge_minor'])->toBe([100000, 120000]);

    $this->withToken(Identity::tokenFor($admin))
        ->deleteJson('/api/v1/services/'.$service->getKey())
        ->assertStatus(204);

    $this->withToken(Identity::tokenFor($admin))
        ->getJson('/api/v1/services/'.$service->getKey())
        ->assertStatus(404);
});

it('enforces service code uniqueness per facility', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/services', [
            'name' => 'ECG', 'code' => 'ecg', 'facilityId' => $facility->getKey(), 'serviceType' => 'investigation',
        ])
        ->assertCreated();

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/organizations/'.$org->getKey().'/services', [
            'name' => 'ECG Again', 'code' => 'ecg', 'facilityId' => $facility->getKey(), 'serviceType' => 'investigation',
        ])
        ->assertStatus(422);
});
