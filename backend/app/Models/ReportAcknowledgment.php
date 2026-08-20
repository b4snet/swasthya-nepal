<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportAcknowledgment extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'compliance_report_id', 'staff_id',
        'action', 'notes', 'acknowledged_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(ComplianceReport::class, 'compliance_report_id');
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }
}
