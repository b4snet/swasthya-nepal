<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Calibration record for medical devices (PRODUCT_REQUIREMENTS §6.18,
 * prompt §81-82). Tracks internal/external/vendor calibration with
 * pass/fail/conditional result and due date for compliance surfacing.
 * Tenant+facility scoped, RLS on + FORCED.
 */
class CalibrationRecord extends Model
{
    /** @use HasFactory<CalibrationRecordFactory> */
    use HasFactory, HasUuid;

    public const TYPE_INTERNAL = 'internal';
    public const TYPE_EXTERNAL = 'external';
    public const TYPE_VENDOR = 'vendor';

    public const RESULT_PASS = 'pass';
    public const RESULT_FAIL = 'fail';
    public const RESULT_CONDITIONAL = 'conditional';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'calibration_type',
        'provider',
        'calibrated_at',
        'due_at',
        'result',
        'notes',
        'performed_by_staff_id',
        'document_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'calibrated_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
