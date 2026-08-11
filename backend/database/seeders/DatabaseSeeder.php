<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The only seeds this platform ships: platform-global catalogs (roles and
 * permissions) that tenants consume but never write (DATABASE.md §3.5–3.6).
 *
 * There is deliberately NO demo user, NO demo organization, NO demo data —
 * tenant data is created through the provisioning flow, never seeded
 * (MASTER_RULES.md P.9: no demo-only functionality, no fake data). The
 * scaffold's original test-user seed was removed for exactly this reason.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
    }
}
