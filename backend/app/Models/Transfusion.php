<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\TransfusionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A transfusion (DATABASE.md §3.50, PRODUCT_REQUIREMENTS §6.12) with DUAL
 * verification: started by one staff member and verified by a DIFFERENT
 * staff member, both recorded with timestamps. A wrong unit is a
 * life-threatening error — the unit must be issued to this patient and the
 * crossmatch must be compatible. Tenant+facility scoped, RLS on + FORCED.
 */
class Transfusion extends Model
{
    /** @use HasFactory<TransfusionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_STARTED = 'started';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_ABORTED = 'aborted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'blood_unit_id',
        'patient_id',
        'crossmatch_id',
        'encounter_id',
        'started_at',
        'started_by_staff_id',
        'verified_at',
        'verified_by_staff_id',
        'stopped_at',
        'stopped_by_staff_id',
        'volume_transfused_ml',
        'status',
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
            'started_at' => 'datetime',
            'verified_at' => 'datetime',
            'stopped_at' => 'datetime',
            'volume_transfused_ml' => 'integer',
            'lock_version' => 'integer',
        ];
    }
}
