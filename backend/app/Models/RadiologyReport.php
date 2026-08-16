<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RadiologyReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A radiology report on a study (DATABASE.md §3.29, PRODUCT_REQUIREMENTS
 * §6.9, CLINICAL_SAFETY §8). The report chain follows the lab discipline:
 *
 *   draft → preliminary → final | amended
 *
 * Preliminary vs. final is explicit and the TIMING is visible (reported_at
 * and verified_at on every row) — the referrer always knows what level of
 * review a report has had. Verification is a distinct audited act by a
 * DIFFERENT radiologist (entry ≠ verification). Every amendment is a NEW
 * row with parent_report_id pointing at the version it amends — the
 * original is preserved, never edited. Exactly one active final report per
 * study (partial unique backstop).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class RadiologyReport extends Model
{
    /** @use HasFactory<RadiologyReportFactory> */
    use HasFactory, HasUuid;

    public const TYPE_PRELIMINARY = 'preliminary';

    public const TYPE_FINAL = 'final';

    public const TYPE_ADDENDUM = 'addendum';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PRELIMINARY = 'preliminary';

    public const STATUS_FINAL = 'final';

    public const STATUS_AMENDED = 'amended';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'study_id',
        'report_type',
        'status',
        'content',
        'impression',
        'critical_findings',
        'reported_by_staff_id',
        'reported_at',
        'verified_by_staff_id',
        'verified_at',
        'parent_report_id',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'verified_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Study, $this>
     */
    public function study(): BelongsTo
    {
        return $this->belongsTo(Study::class, 'study_id');
    }

    /**
     * @return BelongsTo<RadiologyReport, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_report_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reported_by_staff_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'verified_by_staff_id');
    }
}
