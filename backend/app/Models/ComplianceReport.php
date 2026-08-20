<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceReport extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORY_PRIVACY = 'privacy';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_CLINICAL_QUALITY = 'clinical_quality';

    public const CATEGORY_FINANCIAL_CONTROLS = 'financial_controls';

    public const CATEGORY_OPERATIONAL_GOVERNANCE = 'operational_governance';

    protected $fillable = [
        'tenant_id', 'facility_id', 'report_code', 'title',
        'category', 'scope', 'status', 'summary', 'metadata',
        'generated_by_staff_id', 'generated_at', 'published_at',
        'acknowledged_at', 'acknowledgments_required', 'version',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'metadata' => 'array',
            'acknowledgments_required' => 'array',
            'generated_at' => 'datetime',
            'published_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ComplianceReportItem::class, 'compliance_report_id');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(ReportAcknowledgment::class, 'compliance_report_id');
    }
}
