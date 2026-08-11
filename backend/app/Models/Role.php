<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform-global role definitions (DATABASE.md §3.5, MASTER_RULES.md §9).
 *
 * Seeded, never user-creatable per tenant; read-only to tenants. Roles are
 * never deleted — retiring is a status change reviewed with affected
 * assignments. scope_type fixes the role's authorization boundary:
 * platform / organization / facility / branch.
 */
class Role extends Model
{
    use HasUuid;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_FACILITY = 'facility';

    public const SCOPE_BRANCH = 'branch';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'scope_type',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withTimestamps();
    }
}
