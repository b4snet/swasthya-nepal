<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicit, time-limited, fully audited support session (TENANCY.md V2 §8).
 *
 * The ONLY way a platform administrator touches tenant data: the session
 * names the target organization (and optionally one facility), carries a
 * mandatory reason, and expires. While a session is active, the platform
 * administrator's context resolves as a tenant context scoped to the session
 * target with the read-only support_agent role — never with platform
 * privileges. There is deliberately NO "bypass everything" permission.
 *
 * Lifecycle: active → ended (explicit) or treated as expired past expires_at.
 * Rows are never deleted — the session record itself is the audit proof.
 */
class SupportSession extends Model
{
    use HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'organization_id',
        'facility_id',
        'reason',
        'status',
        'opened_at',
        'expires_at',
        'ended_at',
        'ended_by',
        'correlation_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /**
     * @param  Builder<SupportSession>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now());
    }
}
