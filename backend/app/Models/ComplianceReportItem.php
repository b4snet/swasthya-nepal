<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceReportItem extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'compliance_report_id', 'rule_code', 'rule_name',
        'severity', 'status', 'description', 'evidence', 'recommendations',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'recommendations' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ComplianceReport::class, 'compliance_report_id');
    }
}
