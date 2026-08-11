<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform-global permission catalog (DATABASE.md §3.6).
 *
 * Codes are namespaced 'domain:action' (e.g., 'facility:create') and are
 * part of the versioned API contract — additive only, never renamed without
 * a new API version. Permissions are never deleted; retirement is a
 * migration-reviewed change.
 */
class Permission extends Model
{
    use HasUuid;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_BOTH = 'both';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'domain',
        'description',
        'scope',
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
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions')
            ->withTimestamps();
    }
}
