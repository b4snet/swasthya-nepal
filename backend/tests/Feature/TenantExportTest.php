<?php

use App\Models\AuditEvent;
use App\Models\Facility;
use App\Services\Export\ArchiveService;
use App\Services\Export\ExportService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Identity;

/**
 * Tenant data export (TENANCY.md V2 §15, DATA_GOVERNANCE.md §Data Export).
 *
 * Covers the facts-only bundle builder, the tenant-scoped archive path, and
 * the API endpoint — including tenant derivation from context (never client
 * input), the data:export authorization gate, and the audited event.
 */
beforeEach(function (): void {
    seedIdentity();
});

it('builds a tenant-scoped export bundle of facts', function (): void {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $service = app(ExportService::class);

    $bundle = $service->bundle($org->getKey());

    expect($bundle['schema_version'])->toBe(1)
        ->and($bundle['organization']['id'])->toBe($org->getKey())
        ->and($bundle['organization']['name'])->toBe($org->name)
        ->and($bundle['facilities'][0]['id'])->toBe($facility->getKey())
        ->and($bundle['facilities'][0]['code'])->toBe($facility->code)
        ->and($bundle['layers'])->toBeArray()
        ->and($bundle['total_records'])->toBeGreaterThanOrEqual(0)
        ->and($bundle['exported_at'])->not->toBeNull();
});

it('scopes the bundle strictly to the requested tenant', function (): void {
    $orgA = Identity::organization();
    $orgB = Identity::organization();
    Identity::facility($orgA);
    Identity::facility($orgB);
    $service = app(ExportService::class);

    $bundleA = $service->bundle($orgA->getKey());

    // Facility ids in org A's bundle come only from org A.
    $facilityAA = Facility::query()->where('tenant_id', $orgA->getKey())->pluck('id')->all();

    expect(count($bundleA['facilities']))->toBe(1)
        ->and($bundleA['facilities'][0]['id'])->toBe($facilityAA[0])
        ->and($bundleA['organization']['id'])->toBe($orgA->getKey());
});

it('archives the bundle to a tenant-scoped storage path', function (): void {
    Storage::fake('local');

    $org = Identity::organization();
    $service = app(ExportService::class);
    $archive = app(ArchiveService::class);

    $bundle = $service->bundle($org->getKey());
    $artifact = $archive->archive($bundle, $org->getKey());

    expect($artifact['path'])->toStartWith('exports/'.$org->getKey().'/')
        ->and($artifact['export_id'])->not->toBeEmpty()
        ->and($artifact['sizeBytes'])->toBeGreaterThan(0);

    Storage::disk('local')->assertExists($artifact['path']);
});

it('returns 403 for a tenant without the data:export permission', function (): void {
    $org = Identity::organization();
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org);

    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/governance/export')
        ->assertStatus(403);
});

it('creates an export for an authorized org admin and audits it', function (): void {
    Storage::fake('local');

    $org = Identity::organization();
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);

    $response = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/governance/export')
        ->assertStatus(201)
        ->assertHeader('X-Audit-Event-Id');

    expect($response->json('data.tenant_id'))->toBe($org->getKey())
        ->and($response->json('data.path'))->toStartWith('exports/'.$org->getKey().'/');

    // The path resolved from the response must exist on disk.
    Storage::disk('local')->assertExists($response->json('data.path'));

    $audit = AuditEvent::query()
        ->where('action', 'data.export')
        ->where('resource_id', $org->getKey())
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->payload['format'])->toBe('json');
});
