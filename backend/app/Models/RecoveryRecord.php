<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\RecoveryRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A PACU recovery record for a surgical procedure (DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10): post-anesthesia observations and discharge
 * from recovery. Tenant+facility scoped, RLS on + FORCED.
 */
class RecoveryRecord extends Model
{
    /** @use HasFactory<RecoveryRecordFactory> */
    use HasFactory, HasUuid;

    public const STATUS_IN_RECOVERY = 'in_recovery';

    public const STATUS_DISCHARGED = 'discharged';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'admitted_at',
        'admitted_by_staff_id',
        'observations',
        'status',
        'discharged_at',
        'discharged_by_staff_id',
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
            'admitted_at' => 'datetime',
            'observations' => 'array',
            'discharged_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
