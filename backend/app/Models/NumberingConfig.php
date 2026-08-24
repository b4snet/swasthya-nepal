<?php

namespace App\Models;

use App\Services\DocumentNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUuid;

/**
 * Per-tenant configurable numbering (DATABASE.md document identity).
 *
 * Controls how document numbers are generated for each (tenant, document_type).
 */
class NumberingConfig extends Model
{
    use HasUuid;

    /**
     * Reset policies.
     */
    public const RESET_DAILY = 'daily';

    public const RESET_MONTHLY = 'monthly';

    public const RESET_YEARLY = 'yearly';

    public const RESET_NEVER = 'never';

    public const RESET_POLICIES = [
        self::RESET_DAILY,
        self::RESET_MONTHLY,
        self::RESET_YEARLY,
        self::RESET_NEVER,
    ];

    /**
     * All supported document types.
     */
    public const DOCUMENT_TYPES = [
        'uhid' => 'UHID',
        'registration' => 'Registration No.',
        'encounter' => 'Encounter No.',
        'appointment' => 'Appointment No.',
        'admission' => 'Admission No.',
        'investigation_order' => 'Investigation Order No.',
        'sample' => 'Sample No.',
        'lab_report' => 'Laboratory Report No.',
        'prescription' => 'Prescription No.',
        'procedure' => 'Procedure No.',
        'surgery' => 'Surgery No.',
        'consent' => 'Consent No.',
        'discharge' => 'Discharge No.',
        'invoice' => 'Invoice No.',
        'receipt' => 'Receipt No.',
        'referral' => 'Referral No.',
        'form' => 'Form No.',
        'radiology' => 'Radiology No.',
        'blood_unit' => 'Blood Unit No.',
    ];

    protected $fillable = [
        'tenant_id', 'document_type', 'prefix', 'sequence_length',
        'date_format', 'reset_policy', 'include_facility', 'separator',
        'is_active', 'metadata',
    ];

    protected $casts = [
        'sequence_length' => 'integer',
        'include_facility' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * Generate the next number using this configuration.
     */
    public function generateNext(?string $facilityId = null): string
    {
        $service = app(DocumentNumberService::class);

        return $service->nextFromConfig($this, $facilityId);
    }

    /**
     * Get a preview of what the next number will look like.
     */
    public function preview(): string
    {
        $prefix = $this->prefix;
        $sep = $this->separator;
        $seqLen = $this->sequence_length;
        $seq = '1';

        $parts = [$prefix];

        if ($this->include_facility) {
            $parts[] = 'FAC';
        }

        if ($this->date_format) {
            $parts[] = now()->format($this->date_format);
        }

        $parts[] = str_pad($seq, $seqLen, '0', STR_PAD_LEFT);

        return implode($sep, $parts);
    }
}
