<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The immutable context every authenticated request executes inside
 * (TENANCY.md §2, API_CONTRACTS.md §5).
 *
 * Context is a server-side fact derived from the authenticated principal's
 * ACTIVE role assignments — never from client input. The client may only
 * propose facility context (X-Swasthya-Facility) and branch context
 * (X-Swasthya-Branch); the server validates both against the assignments /
 * the resolved facility and derives the tenant from them.
 *
 * Context kinds (TENANCY.md V2 §8):
 *  - platform   — the principal holds a platform-scope assignment and no
 *    support session is active. Authorization is limited to 'platform' and
 *    'both' scope permissions: platform administration only. Tenant data is
 *    unreachable; the RLS GUCs carry an empty tenant, so the database
 *    refuses tenant rows independently.
 *  - support    — a platform principal with an ACTIVE support session: the
 *    context becomes a tenant context scoped to the session target with the
 *    read-only support_agent role. Explicit, time-limited, audited.
 *  - tenant     — ordinary organization/facility context.
 *
 * Authorization (can()): a permission is granted when an active assignment
 * inside the current tenant covers the current facility scope and its role
 * carries the permission. Org-scoped roles cover every facility of the
 * tenant (TENANCY.md §7 rule 3); facility-scoped roles cover exactly one.
 */
final class TenantContext
{
    private static ?self $current = null;

    /**
     * @param  Collection<int, RoleAssignment>  $assignments
     */
    public function __construct(
        public readonly ?User $user,
        public readonly bool $isPlatform,
        public readonly ?Organization $organization,
        public readonly ?Facility $facility,
        public readonly Collection $assignments,
        public readonly ?Branch $branch = null,
        public readonly ?string $supportSessionId = null,
    ) {}

    public static function current(): self
    {
        return self::$current ?? self::empty();
    }

    public static function setCurrent(?self $context): void
    {
        self::$current = $context;
    }

    public static function empty(): self
    {
        return new self(null, false, null, null, collect());
    }

    public function tenantId(): ?string
    {
        return $this->organization?->getKey();
    }

    public function facilityId(): ?string
    {
        return $this->facility?->getKey();
    }

    public function branchId(): ?string
    {
        return $this->branch?->getKey();
    }

    public function timezone(): string
    {
        return $this->facility?->timezone
            ?? $this->organization?->timezone
            ?? config('app.timezone');
    }

    /**
     * Whether the principal may perform $permission in this context.
     *
     * Platform context grants ONLY platform-scope permissions ('platform' /
     * 'both'); tenant business permissions are unreachable without an active
     * support session (TENANCY.md V2 §8). Support and tenant contexts use
     * the normal scoped-assignment check.
     */
    public function can(string $permission): bool
    {
        if ($this->isPlatform) {
            foreach ($this->assignments as $assignment) {
                /** @var RoleAssignment $assignment */
                if ($assignment->role?->scope_type !== Role::SCOPE_PLATFORM) {
                    continue;
                }

                /** @var Permission|null $candidate */
                $candidate = $assignment->role->permissions->firstWhere('code', $permission);

                if ($candidate !== null && $candidate->scope !== 'tenant') {
                    return true;
                }
            }

            return false;
        }

        if ($this->organization === null) {
            return false;
        }

        foreach ($this->assignments as $assignment) {
            /** @var RoleAssignment $assignment */
            if ($assignment->tenant_id !== $this->organization->getKey()) {
                continue;
            }

            // Facility-scoped assignments apply only to their own facility.
            if ($assignment->facility_id !== null) {
                if ($this->facility === null || $assignment->facility_id !== $this->facility->getKey()) {
                    continue;
                }
            }

            $role = $assignment->role;

            if ($role?->permissions->contains('code', $permission)) {
                return true;
            }
        }

        return false;
    }
}
