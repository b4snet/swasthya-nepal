<?php

namespace App\Services\Export;

use App\Models\Facility;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/**
 * Tenant data export (TENANCY.md V2 §15, DATA_GOVERNANCE.md §Data Export).
 *
 * Builds a tenant-scoped, auditable export bundle for portability and
 * offboarding readiness. The bundle records FACTS ONLY — organization and
 * facility configuration, model layers, and record counts — never raw PHI or
 * clinical row contents (MASTER_RULES.md the never-log discipline). This
 * matches how the rest of the governance layer (and the audit trail) treats
 * sensitive data: references and facts over payload, so the artifact can be
 * inspected without leaking patient data.
 *
 * Every export is scoped to exactly one tenant (server-derived, never client
 * chosen) and records its WHO/WHAT/WHEN via the audit trail.
 */
final class ExportService
{
    /**
     * The data layers included in a tenant export bundle, each with its
     * tenant_key: the column used to scope that layer's rows to the tenant.
     *
     * @var array<string, array{
     *     classification: string,
     *     tenant_key: string,
     *     layer: string
     * }>
     */
    private const LAYERS = [
        'departments' => ['classification' => 'configuration', 'tenant_key' => 'tenant_id', 'layer' => 'catalog'],
        'services' => ['classification' => 'configuration', 'tenant_key' => 'tenant_id', 'layer' => 'catalog'],
        'payers' => ['classification' => 'configuration', 'tenant_key' => 'tenant_id', 'layer' => 'catalog'],
        'staff' => ['classification' => 'staff', 'tenant_key' => 'tenant_id', 'layer' => 'people'],
        'patients' => ['classification' => 'patient_identity', 'tenant_key' => 'tenant_id', 'layer' => 'records'],
        'encounters' => ['classification' => 'clinical_record', 'tenant_key' => 'tenant_id', 'layer' => 'records'],
        'invoices' => ['classification' => 'finance', 'tenant_key' => 'tenant_id', 'layer' => 'finance'],
    ];

    /**
     * Build the full export bundle for a single organization.
     *
     * @return array{
     *     schema_version: int,
     *     exported_at: string,
     *     organization: array<string, mixed>,
     *     facilities: list<array<string, mixed>>,
     *     layers: list<array{layer: string, classification: string, record_count: int}>,
     *     total_records: int
     * }
     */
    public function bundle(string $organizationId): array
    {
        /** @var Organization $org */
        $org = Organization::query()->findOrFail($organizationId);

        $facilities = Facility::query()
            ->where('tenant_id', $organizationId)
            ->get(['id', 'name', 'code', 'status']);

        $layers = [];
        $total = 0;

        foreach (self::LAYERS as $table => $meta) {
            $count = DB::table($table)
                ->where($meta['tenant_key'], $organizationId)
                ->count();

            $total += $count;

            $layers[] = [
                'layer' => $meta['layer'],
                'classification' => $meta['classification'],
                'record_count' => $count,
            ];
        }

        return [
            'schema_version' => 1,
            'exported_at' => now()->toIso8601String(),
            'organization' => [
                'id' => $org->getKey(),
                'code' => $org->code,
                'name' => $org->name,
                'status' => $org->status,
            ],
            'facilities' => $facilities->map(fn (Facility $f): array => [
                'id' => $f->getKey(),
                'name' => $f->name,
                'code' => $f->code,
                'status' => $f->status,
            ])->all(),
            'layers' => $layers,
            'total_records' => $total,
        ];
    }

    /**
     * The classification matrix entry for the export permission surface.
     * Exposed for the export endpoint's manifest/authorization reporting.
     *
     * @return array<string, array{classification: string, tenant_key: string, layer: string}>
     */
    public function layers(): array
    {
        return self::LAYERS;
    }
}
