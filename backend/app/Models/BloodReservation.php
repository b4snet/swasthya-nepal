<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A unit-level blood reservation (PRODUCT_REQUIREMENTS §6.12, prompt §76-77):
 * a unit reserved for Patient A must NOT be issued to Patient B. Reservations
 * are scoped to the specific identifiable unit — never merely "2 units of
 * blood." One active reservation per unit at a time (DB unique backstop).
 * Tenant+facility scoped, RLS on + FORCED.
 */
class BloodReservation extends Model
{
    /** @use HasFactory<BloodReservationFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONVERTED = 'converted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'blood_unit_id',
        'patient_id',
        'encounter_id',
        'status',
        'reason',
        'reserved_at',
        'expires_at',
        'reserved_by_staff_id',
        'released_by_staff_id',
        'released_at',
        'release_reason',
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
            'reserved_at' => 'datetime',
            'expires_at' => 'datetime',
            'released_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function bloodUnit(): BelongsTo
    {
        return $this->belongsTo(BloodUnit::class, 'blood_unit_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
