<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ReportScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A scheduled report (DATABASE.md §3.51): a template + cron expression with
 * last/next run stamps. The scheduler advances next_run_at with a CAS on
 * (enabled, last_run_at) so a concurrent worker cannot double-run the same
 * schedule (idempotency). Tenant+facility scoped, RLS on + FORCED.
 */
class ReportSchedule extends Model
{
    /** @use HasFactory<ReportScheduleFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'template_id',
        'cron_expression',
        'enabled',
        'last_run_at',
        'next_run_at',
        'created_by_staff_id',
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
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
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
}
