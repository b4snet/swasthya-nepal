<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restore a database backup with integrity verification.
 *
 * Steps:
 *   1. Verify dump file exists and checksum matches
 *   2. Drop and recreate the target database
 *   3. Run pg_restore
 *   4. Verify row counts against manifest
 *   5. Run post-restore health checks
 */
final class DatabaseRestore extends Command
{
    protected $signature = 'backup:restore
        {dump : Path to the .dump backup file}
        {--target-db= : Target database name (default: overwrites current config db)}
        {--verify : Verify row counts against manifest after restore}
        {--dry-run : Show what would happen without executing}';

    protected $description = 'Restore a database backup with integrity verification';

    public function handle(): int
    {
        $dumpPath = $this->argument('dump');
        $pgBin = $this->findPgBin();
        if ($pgBin === null) {
            $this->error('pg_restore not found.');

            return self::FAILURE;
        }

        if (! file_exists($dumpPath)) {
            $this->error("Dump file not found: {$dumpPath}");

            return self::FAILURE;
        }

        $dbConfig = config('database.connections.pgsql');
        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 5432;
        $username = $dbConfig['username'] ?? 'postgres';
        $password = $dbConfig['password'] ?? '';
        $targetDb = $this->option('target-db') ?? $dbConfig['database'];
        $dryRun = $this->option('dry-run');
        $verify = $this->option('verify');

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' DATABASE RESTORE'.($dryRun ? ' (DRY RUN)' : ''));
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Dump:       {$dumpPath}");
        $this->line("  Target:     {$targetDb}@{$host}:{$port}");
        $this->line('  Verify:     '.($verify ? 'yes' : 'no'));
        $this->newLine();

        // ── Step 1: Verify checksum if manifest exists ──
        $manifestPath = str_replace('.dump', '.manifest.json', $dumpPath);
        $checksumPath = str_replace('.dump', '.sha256', $dumpPath);

        if (file_exists($checksumPath)) {
            $this->info('  [1/5] Verifying checksum...');
            $expectedHash = trim(explode(' ', file_get_contents($checksumPath))[0]);
            $actualHash = hash_file('sha256', $dumpPath);

            if ($expectedHash !== $actualHash) {
                $this->error('  ✗ CHECKSUM MISMATCH!');
                $this->error("    Expected: {$expectedHash}");
                $this->error("    Actual:   {$actualHash}");
                $this->error('    The backup file may be corrupted.');

                return self::FAILURE;
            }
            $this->line("    ✓ Checksum verified: {$actualHash}");
        } else {
            $this->warn('  [1/5] No checksum file — skipping verification');
        }

        // ── Step 2: Drop and recreate database ──
        $this->info('  [2/5] '.($dryRun ? 'Would drop and recreate' : 'Dropping and recreating').' database...');

        if (! $dryRun) {
            // Terminate existing connections
            $this->line('    Terminating active connections...');
            DB::statement('SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()', [$targetDb]);

            // Drop
            $this->line('    Dropping database...');
            DB::statement("DROP DATABASE IF EXISTS \"{$targetDb}\"");

            // Recreate
            $this->line('    Creating database...');
            DB::statement("CREATE DATABASE \"{$targetDb}\"");

            $this->line('    ✓ Database recreated');
        } else {
            $this->line("    [DRY RUN] Would drop and recreate {$targetDb}");
        }

        // ── Step 3: pg_restore ──
        $this->info('  [3/5] Running pg_restore...');
        $start = microtime(true);

        $pgRestore = escapeshellarg(rtrim($pgBin, '/\\').'/pg_restore.exe');
        $cmd = $pgRestore
            .' -h '.escapeshellarg($host)
            .' -p '.escapeshellarg((string) $port)
            .' -U '.escapeshellarg($username)
            .' -d '.escapeshellarg($targetDb)
            .' --no-owner'
            .' --no-acl'
            .' --verbose'
            .' '.escapeshellarg($dumpPath);

        if ($dryRun) {
            $cmd .= ' --list';
        }

        $output = [];
        $exitCode = 0;
        if ($password) {
            putenv('PGPASSWORD='.$password);
        }
        exec($cmd.' 2>&1', $output, $exitCode);
        $elapsed = round(microtime(true) - $start, 2);

        if ($exitCode !== 0 && ! $dryRun) {
            // pg_restore returns non-zero for warnings too; check if DB has tables
            $tableCount = (int) DB::connection('pgsql')->select("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = 'public'")[0]->cnt;
            if ($tableCount === 0) {
                $this->error("  pg_restore failed (exit code {$exitCode}) and no tables found");
                foreach (array_slice($output, -15) as $line) {
                    $this->error("    {$line}");
                }

                return self::FAILURE;
            }
            $this->warn("  ⚠ pg_restore completed with warnings (exit code {$exitCode})");
        } else {
            $this->line("    ✓ pg_restore completed in {$elapsed}s");
        }

        // ── Step 4: Post-restore verification ──
        $this->info('  [4/5] Post-restore verification...');

        if ($dryRun) {
            $this->line('    [DRY RUN] Would verify restored tables');
        } else {
            $restoredTables = DB::connection('pgsql')->select("SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = 'public'");
            $restoredCount = $restoredTables[0]->cnt ?? 0;
            $this->line("    ✓ {$restoredCount} tables found in restored database");
        }

        // ── Step 5: Manifest row-count comparison ──
        if ($verify && file_exists($manifestPath) && ! $dryRun) {
            $this->info('  [5/5] Comparing row counts against manifest...');

            $manifest = json_decode(file_get_contents($manifestPath), true);
            $originalTables = $manifest['schema']['tables'] ?? [];
            $mismatches = 0;

            foreach ($originalTables as $tableInfo) {
                $table = $tableInfo['table'];
                $expected = $tableInfo['row_count'];
                try {
                    $actual = (int) DB::connection('pgsql')->select("SELECT COUNT(*) as cnt FROM public.\"{$table}\"")[0]->cnt;
                } catch (\Throwable) {
                    $this->error("    ✗ Table {$table} missing after restore");
                    $mismatches++;

                    continue;
                }

                if ($actual !== $expected) {
                    $this->warn("    ⚠ {$table}: expected {$expected}, got {$actual}");
                    $mismatches++;
                }
            }

            if ($mismatches === 0) {
                $this->line("    ✓ All {$restoredCount} tables match manifest row counts");
            } else {
                $this->warn("    ⚠ {$mismatches} table(s) have mismatched row counts");
            }
        } else {
            $this->line('  [5/5] Row-count verification skipped');
        }

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' RESTORE '.($dryRun ? 'DRY RUN ' : '').'COMPLETE');
        $this->info('═══════════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    private function findPgBin(): ?string
    {
        $env = getenv('PG_BIN_PATH');
        if ($env && is_dir($env)) {
            return $env;
        }

        $toolchain = base_path().'/../.toolchain/pgsql/pgsql/bin';
        if (is_dir($toolchain)) {
            return $toolchain;
        }

        $output = [];
        exec('which pg_restore 2>/dev/null || where pg_restore 2>/dev/null', $output, $exit);
        if ($exit === 0 && ! empty($output[0])) {
            return dirname($output[0]);
        }

        return null;
    }
}
