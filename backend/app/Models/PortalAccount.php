<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PortalAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A patient's portal identity (DATABASE.md §3.53, PRODUCT REQUIREMENTS
 * §6.2): one account per patient per tenant, authenticated with
 * identifier + password, DB-backed lockout, Sanctum access tokens. The
 * portal NEVER trusts the client for the patient identity — it is derived
 * from this account on every request. Tenant+facility scoped, RLS on +
 * FORCED.
 */
class PortalAccount extends Model
{
    /** @use HasFactory<PortalAccountFactory> */
    use HasApiTokens, HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'login_identifier',
        'password_hash',
        'status',
        'failed_attempts',
        'locked_until',
        'mfa_enabled',
        'last_login_at',
        'lock_version',
        'created_by_staff_id',
        'updated_by_staff_id',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'mfa_enabled' => 'boolean',
            'last_login_at' => 'datetime',
            'lock_version' => 'integer',
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
     * @return HasMany<PortalSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(PortalSession::class, 'portal_account_id');
    }

    /**
     * @return HasMany<PortalAccessGrant, $this>
     */
    public function grants(): HasMany
    {
        return $this->hasMany(PortalAccessGrant::class, 'portal_account_id');
    }
}
