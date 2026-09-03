<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Packages a tenant export bundle into a persistent, tenant-scoped archive.
 *
 * The bundle is written to the tenant-isolated storage path
 * (exports/{tenantId}/{exportId}.json) so a download cannot address another
 * tenant's prefix (TENANCY.md §5). The artifact is a facts-only bundle
 * (see ExportService) — it carries configuration and record counts, never raw
 * PHI — so persisting it does not create a new sensitive-data store.
 */
final class ArchiveService
{
    /**
     * Persist an export bundle and return its tenant-scoped storage path.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{path: string, export_id: string, sizeBytes: int}
     */
    public function archive(array $bundle, string $tenantId): array
    {
        $exportId = (string) Str::uuid7();
        $json = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \RuntimeException('Unable to encode export bundle as JSON.');
        }

        $path = "exports/{$tenantId}/{$exportId}.json";

        Storage::disk('local')->put($path, $json);

        return [
            'path' => $path,
            'export_id' => $exportId,
            'sizeBytes' => strlen($json),
        ];
    }
}
