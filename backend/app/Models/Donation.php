<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DonationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A donation event (DATABASE.md §3.50, PRODUCT_REQUIREMENTS §6.12):
 * donor, phlebotomist, volume, screening result. Processing into
 * componentized blood units is the next step. Tenant+facility scoped,
 * RLS on + FORCED.
 */
class Donation extends Model
{
    /** @use HasFactory<DonationFactory> */
    use HasFactory, HasUuid;

    public const STATUS_COLLECTED = 'collected';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_DISCARDED = 'discarded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'donor_id',
        'donated_at',
        'phlebotomist_staff_id',
        'volume_ml',
        'screening_result',
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
            'donated_at' => 'datetime',
            'volume_ml' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Donor, $this>
     */
    public function donor(): BelongsTo
    {
        return $this->belongsTo(Donor::class, 'donor_id');
    }
}
