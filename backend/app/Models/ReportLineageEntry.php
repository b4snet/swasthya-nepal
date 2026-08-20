<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportLineageEntry extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'report_run_id', 'source_table', 'source_id',
        'metric_code', 'snapshot_context',
    ];

    protected function casts(): array
    {
        return ['snapshot_context' => 'array'];
    }

    public function reportRun(): BelongsTo
    {
        return $this->belongsTo(ReportRun::class, 'report_run_id');
    }
}
