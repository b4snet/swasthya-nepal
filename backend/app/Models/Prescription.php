<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The prescription header (DATABASE.md §3.21): what was prescribed, by
 * whom, for whom. Never soft-deleted — discontinuation is a status.
 */
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFTED = 'drafted';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISPENSED = 'dispensed';

    public const STATUS_DISCONTINUED = 'discontinued';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'encounter_id',
        'prescriber_staff_id',
        'status',
        'notes',
        'lock_version',
        'verified_by_staff_id',
        'verified_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lock_version' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'prescriber_staff_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'verified_by_staff_id');
    }

    /**
     * @return HasMany<PrescriptionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PrescriptionLine::class, 'prescription_id');
    }
}
