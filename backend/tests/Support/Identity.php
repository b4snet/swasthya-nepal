<?php

namespace Tests\Support;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Test-support helpers for building tenants, identities, and assignments.
 *
 * These are test fixtures — never production data. Passwords here are
 * deliberately strong-looking strings (no default credentials, MASTER_RULES
 * §7.7) that only exist inside a test database.
 */
final class Identity
{
    public const PASSWORD = 'correct-horse-battery-staple';

    public static function organization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    public static function facility(Organization $organization, array $attributes = []): Facility
    {
        return Facility::factory()->create(array_merge(
            ['tenant_id' => $organization->getKey()],
            $attributes,
        ));
    }

    public static function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(
            ['password_hash' => self::PASSWORD],
            $attributes,
        ));
    }

    public static function assign(
        User $user,
        string $roleCode,
        ?Organization $organization = null,
        ?Facility $facility = null,
    ): RoleAssignment {
        $role = Role::query()->where('code', $roleCode)->first();

        // Auto-seed RBAC catalog if the role doesn't exist yet.
        // This handles tests that don't explicitly call seedIdentity().
        if (! $role) {
            DB::unprepared('TRUNCATE TABLE role_permissions CASCADE');
            DB::unprepared('TRUNCATE TABLE roles CASCADE');
            DB::unprepared('TRUNCATE TABLE permissions CASCADE');
            app(RolePermissionSeeder::class)->run();
            $role = Role::query()->where('code', $roleCode)->firstOrFail();
        }

        return RoleAssignment::query()->create([
            'user_id' => $user->getKey(),
            'role_id' => $role->getKey(),
            // Platform roles have no tenant (schema CHECK enforces this).
            'tenant_id' => $role->scope_type === Role::SCOPE_PLATFORM ? null : $organization?->getKey(),
            'facility_id' => $facility?->getKey(),
            'scope_type' => $role->scope_type,
            'status' => RoleAssignment::STATUS_ACTIVE,
            'granted_at' => now(),
        ]);
    }

    /**
     * A fresh bearer access token for the user.
     */
    public static function tokenFor(User $user): string
    {
        return $user->createToken('test-access')->plainTextToken;
    }
}
