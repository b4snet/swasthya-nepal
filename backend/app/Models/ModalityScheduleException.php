<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ModalityScheduleExceptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Modality schedule exceptions: blocked time or extra hours outside normal
 * operating hours. Supports maintenance, holidays, or extended sessions.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class ModalityScheduleException extends Model
{
    /** @use HasFactory<ModalityScheduleExceptionFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'modality_id',
        'exception_date',
        'start_time',    // null = all day
        'end_time',      // null = all day
        'reason',        // maintenance, holiday, extended_hours
        'is_blocked',    // true = blocked, false = extra hours
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
            'exception_date' => 'date',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'is_blocked' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class, 'modality_id');
    }
}