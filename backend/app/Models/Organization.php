<?php

namespace App\Models;

use App\Exceptions\ApiException;
use App\Models\Concerns\HasUuid;
use App\Support\ErrorCodes;
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

    // ── Lifecycle state machine (TENANCY.md V2 §13) ──
    //
    // Active → suspended → closed → offboarded, with reactivation back to
    // active from suspended, and close/offboard permitted from active or
    // suspended. 'offboarded' is terminal (the tenant is a tombstone; its
    // data is purged per policy, the row is never soft-deleted).

    /**
     * The only legal status transitions (current → list of permitted targets).
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        self::STATUS_ACTIVE => [self::STATUS_SUSPENDED, self::STATUS_CLOSED, self::STATUS_OFFBOARDED],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_CLOSED, self::STATUS_OFFBOARDED],
        self::STATUS_CLOSED => [self::STATUS_OFFBOARDED],
        self::STATUS_OFFBOARDED => [],
    ];

    /**
     * Whether this organization may legally transition to $target.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function canTransitionTo(string $target): array
    {
        $allowed = in_array($target, self::TRANSITIONS[$this->status] ?? [], true);

        return [
            'allowed' => $allowed,
            'reason' => $allowed
                ? "Transition from '{$this->status}' to '{$target}' is valid"
                : "Transition from '{$this->status}' to '{$target}' is not permitted",
        ];
    }

    /**
     * Transition the organization to $target, enforcing the state machine.
     *
     * Throws an ApiException (409 INVALID_REQUEST) on an illegal transition.
     * Callers are responsible for auditing and persisting within a transaction.
     */
    public function transitionStatus(
        string $target,
        ?string $updatedBy = null,
    ): void {
        $check = $this->canTransitionTo($target);

        if (! $check['allowed']) {
            throw new ApiException(
                ErrorCodes::INVALID_REQUEST,
                $check['reason'],
                409,
            );
        }

        $this->status = $target;
        $this->updated_by = $updatedBy;
        $this->save();
    }

    /**
     * @return HasMany<Facility, $this>
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'tenant_id');
    }
}
