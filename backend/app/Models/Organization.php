<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant: a hospital group or company (DATABASE.md §3.1, TENANCY.md §0).
 *
 * The organization IS the tenant — the isolation, subscription, and billing
 * boundary. It carries no tenant_id and is never soft-deleted: status moves
 * to 'offboarded' and data is purged per policy (TENANCY.md §14).
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_OFFBOARDED = 'offboarded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'status',
        'currency',
        'timezone',
        'locale',
        'tax_config',
        'settings',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_config' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * @return HasMany<Facility, $this>
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'tenant_id');
    }
}
