<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Centralized document registry (Phase 84): every generated document across
 * the application is tracked here with its category, source linkage,
 * branding snapshot, verification status, and access controls.
 *
 * Documents are generated with hospital branding, patient identifiers,
 * provider information, document numbers, and timestamps.
 */
class GeneratedDocument extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    // Document types
    public const TYPE_LAB_REPORT = 'lab_report';

    public const TYPE_RADIOLOGY_REPORT = 'radiology_report';

    public const TYPE_DISCHARGE_SUMMARY = 'discharge_summary';

    public const TYPE_INVOICE = 'invoice';

    public const TYPE_RECEIPT = 'receipt';

    public const TYPE_PRESCRIPTION = 'prescription';

    public const TYPE_REFERRAL = 'referral';

    public const TYPE_CONSENT = 'consent';

    public const TYPE_FORM = 'form';

    public const TYPE_CLINICAL_NOTE = 'clinical_note';

    public const TYPE_OTHER = 'other';

    // Categories
    public const CATEGORY_CLINICAL = 'clinical';

    public const CATEGORY_FINANCIAL = 'financial';

    public const CATEGORY_ADMINISTRATIVE = 'administrative';

    public const CATEGORY_OPERATIONAL = 'operational';

    public const CATEGORY_COMPLIANCE = 'compliance';

    // Statuses
    public const STATUS_GENERATED = 'generated';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FINAL = 'final';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'document_number',
        'document_type',
        'category',
        'title',
        'source_type',
        'source_id',
        'patient_id',
        'provider_staff_id',
        'provider_name',
        'department_name',
        'content_html',
        'content_text',
        'metadata',
        'branding_snapshot',
        'status',
        'verified',
        'verified_by_staff_id',
        'verified_at',
        'signed',
        'signed_by_staff_id',
        'signed_at',
        'printable',
        'pdf_capable',
        'pdf_path',
        'page_count',
        'visibility',
        'shared_with_patient',
        'shared_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'branding_snapshot' => 'array',
            'verified' => 'boolean',
            'verified_at' => 'datetime',
            'signed' => 'boolean',
            'signed_at' => 'datetime',
            'printable' => 'boolean',
            'pdf_capable' => 'boolean',
            'page_count' => 'integer',
            'shared_with_patient' => 'boolean',
            'shared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'provider_staff_id');
    }

    /**
     * List all document types.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_LAB_REPORT => 'Laboratory Report',
            self::TYPE_RADIOLOGY_REPORT => 'Radiology Report',
            self::TYPE_DISCHARGE_SUMMARY => 'Discharge Summary',
            self::TYPE_INVOICE => 'Invoice',
            self::TYPE_RECEIPT => 'Receipt',
            self::TYPE_PRESCRIPTION => 'Prescription',
            self::TYPE_REFERRAL => 'Referral',
            self::TYPE_CONSENT => 'Consent Form',
            self::TYPE_FORM => 'Form',
            self::TYPE_CLINICAL_NOTE => 'Clinical Note',
            self::TYPE_OTHER => 'Other',
        ];
    }

    /**
     * List all categories.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_CLINICAL => 'Clinical',
            self::CATEGORY_FINANCIAL => 'Financial',
            self::CATEGORY_ADMINISTRATIVE => 'Administrative',
            self::CATEGORY_OPERATIONAL => 'Operational',
            self::CATEGORY_COMPLIANCE => 'Compliance',
        ];
    }

    /**
     * Present as API-safe array.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'documentNumber' => $this->document_number,
            'documentType' => $this->document_type,
            'category' => $this->category,
            'title' => $this->title,
            'sourceType' => $this->source_type,
            'sourceId' => $this->source_id,
            'patientId' => $this->patient_id,
            'patientName' => $this->patient?->full_name,
            'patientMrn' => $this->patient?->mrn,
            'providerName' => $this->provider_name,
            'departmentName' => $this->department_name,
            'status' => $this->status,
            'verified' => $this->verified,
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            'signed' => $this->signed,
            'signedAt' => $this->signed_at?->toIso8601String(),
            'printable' => $this->printable,
            'pdfCapable' => $this->pdf_capable,
            'hasPdf' => $this->pdf_path !== null,
            'pageCount' => $this->page_count,
            'visibility' => $this->visibility,
            'sharedWithPatient' => $this->shared_with_patient,
            'sharedAt' => $this->shared_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
