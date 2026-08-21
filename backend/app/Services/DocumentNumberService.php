<?php

namespace App\Services;

use App\Models\DocumentNumber;
use App\Models\NumberingConfig;
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
     * Generate the next document number using a NumberingConfig.
     *
     * Supports:
     * - Custom prefix
     * - Configurable sequence length (zero-padded)
     * - Date component (daily/monthly/yearly/never reset)
     * - Facility component
     * - Custom separator
     */
    public function nextFromConfig(NumberingConfig $config, ?string $facilityId = null): string
    {
        return DB::transaction(function () use ($config, $facilityId) {
            $prefix = $config->prefix;
            $sep = $config->separator;
            $seqLen = $config->sequence_length;

            // Build the date component and reset key based on reset policy
            $dateComponent = null;
            $resetKey = '';

            if ($config->date_format) {
                $dateComponent = now()->format($config->date_format);
            }

            switch ($config->reset_policy) {
                case NumberingConfig::RESET_DAILY:
                    $resetKey = $dateComponent ?? now()->format('Ymd');
                    break;
                case NumberingConfig::RESET_MONTHLY:
                    $resetKey = $dateComponent ?? now()->format('Ym');
                    break;
                case NumberingConfig::RESET_YEARLY:
                    $resetKey = $dateComponent ?? now()->format('Y');
                    break;
                case NumberingConfig::RESET_NEVER:
                default:
                    $resetKey = '';
                    break;
            }

            // Build the WHERE clause for sequence lookup
            $where = [
                ['tenant_id', '=', $config->tenant_id],
                ['document_type', '=', $config->document_type],
            ];

            if ($resetKey !== '') {
                $where[] = ['reset_key', '=', $resetKey];
            }

            if ($config->include_facility && $facilityId) {
                $where[] = ['facility_id', '=', $facilityId];
            }

            // Lock the sequence row
            $last = DB::table('document_numbers')
                ->where($where)
                ->lockForUpdate()
                ->orderBy('sequence', 'desc')
                ->first();

            $sequence = ($last->sequence ?? 0) + 1;

            // Build the full number
            $parts = [$prefix];

            if ($config->include_facility && $facilityId) {
                $shortFacility = substr($facilityId, 0, 4);
                $parts[] = $shortFacility;
            }

            if ($dateComponent) {
                $parts[] = $dateComponent;
            }

            $parts[] = str_pad((string) $sequence, $seqLen, '0', STR_PAD_LEFT);

            $fullNumber = implode($sep, $parts);

            DB::table('document_numbers')->insert([
                'tenant_id' => $config->tenant_id,
                'document_type' => $config->document_type,
                'prefix' => $prefix,
                'sequence' => $sequence,
                'full_number' => $fullNumber,
                'facility_id' => $facilityId,
                'reset_key' => $resetKey ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $fullNumber;
        });
    }

    /**
     * Generate the next document number for a given type and tenant.
     *
     * Format: {PREFIX}-{YYYYMMDD}-{SEQUENCE:5d}
     * Example: FM-20260821-00001
     */
    public function next(string $tenantId, string $documentType, ?string $facilityId = null): string
    {
        // Check for a custom config first
        $config = NumberingConfig::where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('is_active', true)
            ->first();

        if ($config) {
            return $this->nextFromConfig($config, $facilityId);
        }

        // Fallback to default format
        $prefix = DocumentNumber::PREFIXES[$documentType] ?? strtoupper(substr($documentType, 0, 3));

        return DB::transaction(function () use ($tenantId, $documentType, $facilityId, $prefix) {
            $date = now()->format('Ymd');
            $searchPrefix = "{$prefix}-{$date}";

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
