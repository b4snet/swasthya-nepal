<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\BloodUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A componentized blood unit with full lifecycle traceability
 * (DATABASE.md §3.50, PRODUCT_REQUIREMENTS §6.12):
 *
 *   quarantined → available → crossmatched → issued → transfused
 *                                        ↘ discarded
 *
 * EXPIRED or untested units are never issuable (the CAS issue guard refuses
 * them). Every unit is traceable to its donor (via donation) and its
 * recipient (issued_to_patient_id / transfusion). Tenant+facility scoped,
 * RLS on + FORCED.
 */
class BloodUnit extends Model
{
    /** @use HasFactory<BloodUnitFactory> */
    use HasFactory, HasUuid;

    public const STATUS_QUARANTINED = 'quarantined';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_CROSSMATCHED = 'crossmatched';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_TRANSFUSED = 'transfused';

    public const STATUS_DISCARDED = 'discarded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'donation_id',
        'unit_number',
        'component_type',
        'blood_group',
        'rh_factor',
        'collected_at',
        'expiry_at',
        'tested',
        'test_results',
        'status',
        'issued_to_patient_id',
        'storage_location',
        'discard_reason',
        'discarded_at',
        'discarded_by_staff_id',
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
            'collected_at' => 'datetime',
            'expiry_at' => 'datetime',
            'tested' => 'boolean',
            'test_results' => 'array',
            'discarded_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Donation, $this>
     */
    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }
}
