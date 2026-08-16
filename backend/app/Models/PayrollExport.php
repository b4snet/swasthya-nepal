<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PayrollExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An audited payroll-ready export (PRODUCT_REQUIREMENTS §6.17, DATABASE.md
 * §3.45): a structured, point-in-time snapshot of worked days, shifts, and
 * leave for a period, handed to a payroll engine. The row records WHO
 * exported WHAT and WHEN (exported_by_staff_id, period, row_count,
 * payload_hash) — the acceptance criterion "payroll export is accurate and
 * audited". The payload is hashed at generation; the export itself is
 * delivered at generation time and never re-served from the DB.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class PayrollExport extends Model
{
    /** @use HasFactory<PayrollExportFactory> */
    use HasFactory, HasUuid;

    public const FORMAT_PAYROLL_READY = 'payroll_ready';

    public const FORMAT_CSV = 'csv';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'period_start',
        'period_end',
        'exported_by_staff_id',
        'row_count',
        'format',
        'payload_hash',
        'exported_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'row_count' => 'integer',
            'exported_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'exported_by_staff_id');
    }
}
