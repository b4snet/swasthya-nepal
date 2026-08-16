<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\CrossmatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A crossmatch of one blood unit against one patient (DATABASE.md §3.50,
 * PRODUCT_REQUIREMENTS §6.12). Only a COMPATIBLE crossmatch makes the unit
 * issuable to that patient. One crossmatch per (unit, patient) — the DB
 * unique backstops duplicate matching. Tenant+facility scoped,
 * RLS on + FORCED.
 */
class Crossmatch extends Model
{
    /** @use HasFactory<CrossmatchFactory> */
    use HasFactory, HasUuid;

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_CROSSMATCHED = 'crossmatched';

    public const STATUS_COMPATIBLE = 'compatible';

    public const STATUS_INCOMPATIBLE = 'incompatible';

    public const STATUS_RELEASED = 'released';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'blood_unit_id',
        'patient_id',
        'compatibility_result_id',
        'status',
        'requested_at',
        'requested_by_staff_id',
        'crossmatched_at',
        'crossmatched_by_staff_id',
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
            'requested_at' => 'datetime',
            'crossmatched_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
