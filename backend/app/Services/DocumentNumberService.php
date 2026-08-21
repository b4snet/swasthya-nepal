<?php

namespace App\Services;

use App\Models\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Collision-safe document number generation.
 *
 * Uses database-level SELECT FOR UPDATE to prevent concurrent collisions.
 * Each (tenant, document_type) pair maintains its own sequence.
 */
class DocumentNumberService
{
    /**
     * Generate the next document number for a given type and tenant.
     *
     * Format: {PREFIX}-{YYYYMMDD}-{SEQUENCE:5d}
     * Example: FM-20260821-00001
     */
    public function next(string $tenantId, string $documentType, ?string $facilityId = null): string
    {
        $prefix = DocumentNumber::PREFIXES[$documentType] ?? strtoupper(substr($documentType, 0, 3));

        return DB::transaction(function () use ($tenantId, $documentType, $facilityId, $prefix) {
            $date = now()->format('Ymd');
            $searchPrefix = "{$prefix}-{$date}";

            // Lock the sequence row for this tenant + type + date prefix
            $last = DB::selectOne(
                'SELECT sequence FROM document_numbers '
                .'WHERE tenant_id = ? AND document_type = ? AND full_number LIKE ? '
                .'ORDER BY sequence DESC LIMIT 1 FOR UPDATE',
                [$tenantId, $documentType, "{$searchPrefix}%"]
            );

            $sequence = ($last->sequence ?? 0) + 1;
            $fullNumber = sprintf('%s-%s-%05d', $prefix, $date, $sequence);

            DB::table('document_numbers')->insert([
                'tenant_id' => $tenantId,
                'document_type' => $documentType,
                'prefix' => $prefix,
                'sequence' => $sequence,
                'full_number' => $fullNumber,
                'facility_id' => $facilityId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $fullNumber;
        });
    }

    /**
     * Generate a simple sequential number without date component.
     * Used where the prefix alone is sufficient (e.g., UHID, MRN).
     */
    public function nextSimple(string $tenantId, string $documentType, string $prefix): string
    {
        return DB::transaction(function () use ($tenantId, $documentType, $prefix) {
            $last = DB::selectOne(
                'SELECT sequence FROM document_numbers '
                .'WHERE tenant_id = ? AND document_type = ? '
                .'ORDER BY sequence DESC LIMIT 1 FOR UPDATE',
                [$tenantId, $documentType]
            );

            $sequence = ($last->sequence ?? 0) + 1;
            $fullNumber = sprintf('%s-%06d', $prefix, $sequence);

            DB::table('document_numbers')->insert([
                'tenant_id' => $tenantId,
                'document_type' => $documentType,
                'prefix' => $prefix,
                'sequence' => $sequence,
                'full_number' => $fullNumber,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $fullNumber;
        });
    }

    /**
     * Check if a document number already exists.
     */
    public function exists(string $tenantId, string $documentType, string $fullNumber): bool
    {
        return DB::table('document_numbers')
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('full_number', $fullNumber)
            ->exists();
    }
}
