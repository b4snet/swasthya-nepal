<?php

namespace App\Console\Commands;

use Database\Seeders\NepalFinanceSeeder;
use Illuminate\Console\Command;

/**
 * Seed Nepal financial architecture data for a specific tenant.
 *
 * Usage:
 *   php artisan nepal:finance:seed --tenant_id=<uuid> [--facility_id=<uuid>]
 */
final class SeedNepalFinance extends Command
{
    protected $signature = 'nepal:finance:seed
        {--tenant_id= : The organization/tenant UUID to seed (required)}
        {--facility_id= : Optional facility UUID for facility-scoped rules}';

    protected $description = 'Seed Nepal financial architecture (tax rules, payers, benefit rules, fiscal year)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant_id');
        if (! $tenantId) {
            $this->error('--tenant_id is required.');

            return 1;
        }

        $facilityId = $this->option('facility_id') ?: null;

        $seeder = new NepalFinanceSeeder();
        $seeder->setCommand($this);
        $seeder->run([
            'tenant_id' => $tenantId,
            'facility_id' => $facilityId,
        ]);

        return 0;
    }
}
