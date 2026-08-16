<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ReportRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audited report execution (DATABASE.md §3.51, MASTER_RULES.md §19.3):
 * who ran what scope when, the row_count produced, and the outcome. Exports
 * are flagged (reports:export is a separate permission). error_message and
 * all audit payloads carry facts only — never PHI (OBSERVABILITY.md §17).
 * Tenant+facility scoped, RLS on + FORCED.
 */
class ReportRun extends Model
{
    /** @use HasFactory<ReportRunFactory> */
    use HasFactory, HasUuid;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const EXPORT_CSV = 'csv';

    public const EXPORT_PDF = 'pdf';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'template_id',
        'schedule_id',
        'requested_by_staff_id',
        'status',
        'run_at',
        'completed_at',
        'row_count',
        'error_message',
        'is_export',
        'export_format',
        'output_checksum',
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
            'run_at' => 'datetime',
            'completed_at' => 'datetime',
            'row_count' => 'integer',
            'is_export' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<ReportSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'schedule_id');
    }
}
