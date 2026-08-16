<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DonorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A blood donor (DATABASE.md §3.50, PRODUCT_REQUIREMENTS §6.12). Personal
 * data (name, DOB, phone) is protected to the same standard as patient
 * data — never in audit payloads. Tenant+facility scoped, RLS on + FORCED.
 */
class Donor extends Model
{
    /** @use HasFactory<DonorFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEFERRED = 'deferred';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'donor_number',
        'full_name',
        'date_of_birth',
        'sex',
        'blood_group',
        'rh_factor',
        'phone',
        'status',
        'deferral_reason',
        'deferral_until',
        'screening',
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
            'date_of_birth' => 'date',
            'deferral_until' => 'date',
            'screening' => 'array',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<Donation, $this>
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'donor_id');
    }
}
