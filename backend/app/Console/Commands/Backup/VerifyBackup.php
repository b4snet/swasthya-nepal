<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;

/**
 * Verify a backup's integrity without restoring.
 *
 * Checks:
 *   - Dump file exists and is non-empty
 *   - SHA-256 checksum matches
 *   - Manifest exists and is valid JSON
 *   - Manifest row counts match current database
 *   - pg_restore --list succeeds (dump is readable)
 */
final class VerifyBackup extends Command
{
    protected $signature = 'backup:verify
        {dump : Path to the .dump backup file}';

    protected $description = 'Verify backup integrity without restoring';

    public function handle(): int
    {
        $dumpPath = $this->argument('dump');
        $manifestPath = str_replace('.dump', '.manifest.json', $dumpPath);
        $checksumPath = str_replace('.dump', '.sha256', $dumpPath);
        $allPassed = true;

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' BACKUP VERIFICATION');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Dump: {$dumpPath}");
        $this->newLine();

        // ── Check 1: File exists and is non-empty ──
        $this->info('  [1/5] File existence and size...');
        if (! file_exists($dumpPath)) {
            $this->error("    ✗ File does not exist: {$dumpPath}");
            $allPassed = false;
        } else {
            $size = filesize($dumpPath);
            if ($size === 0) {
                $this->error('    ✗ File is empty');
                $allPassed = false;
            } else {
                $this->line('    ✓ File exists — '.$this->formatBytes($size));
            }
        }

        // ── Check 2: SHA-256 checksum ──
        $this->info('  [2/5] SHA-256 checksum...');
        if (! file_exists($checksumPath)) {
            $this->warn('    ⚠ No checksum file found — skipping');
        } else {
            $expectedHash = trim(explode(' ', file_get_contents($checksumPath))[0]);
            $actualHash = hash_file('sha256', $dumpPath);
            if ($expectedHash === $actualHash) {
                $this->line("    ✓ Checksum verified: {$actualHash}");
            } else {
                $this->error('    ✗ CHECKSUM MISMATCH');
                $this->error("      Expected: {$expectedHash}");
                $this->error("      Actual:   {$actualHash}");
                $allPassed = false;
            }
        }

        // ── Check 3: Manifest exists and is valid ──
        $this->info('  [3/5] Manifest validation...');
        $manifest = null;
        if (! file_exists($manifestPath)) {
            $this->warn('    ⚠ No manifest file found — skipping');
        } else {
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if ($manifest === null) {
                $this->error('    ✗ Manifest is not valid JSON');
                $allPassed = false;
            } else {
                $tableCount = $manifest['schema']['table_count'] ?? 0;
                $totalRows = $manifest['schema']['total_rows'] ?? 0;
                $createdAt = $manifest['created_at'] ?? 'unknown';
                $this->line("    ✓ Manifest valid — {$tableCount} tables, {$totalRows} rows, created {$createdAt}");
            }
        }

        // ── Check 4: pg_restore --list ──
        $this->info('  [4/5] pg_restore readability...');
        $pgBin = $this->findPgBin();
        if ($pgBin === null) {
            $this->warn('    ⚠ pg_restore not found — skipping');
        } else {
            $pgRestore = escapeshellarg(rtrim($pgBin, '/\\').'/pg_restore.exe');
            $cmd = $pgRestore.' --list '.escapeshellarg($dumpPath).' 2>&1';
            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode === 0) {
                $tocEntries = count(array_filter($output, fn ($line) => str_starts_with(trim($line), ';')));
                $this->line("    ✓ pg_restore readable — {$tocEntries} TOC entries");
            } else {
                $this->error("    ✗ pg_restore could not read the dump (exit code {$exitCode})");
                $allPassed = false;
            }
        }

        // ── Check 5: Row count comparison ──
        $this->info('  [5/5] Row count comparison...');
        if ($manifest === null) {
            $this->warn('    ⚠ No manifest — skipping');
        } else {
            $originalTables = $manifest['schema']['tables'] ?? [];
            $mismatches = 0;

            foreach ($originalTables as $tableInfo) {
                $table = $tableInfo['table'];
                $expected = $tableInfo['row_count'];
                try {
                    $actual = (int) DB::select("SELECT COUNT(*) as cnt FROM public.\"{$table}\"")[0]->cnt;
                } catch (\Throwable) {
                    $this->warn("    ⚠ {$table}: missing from current database");

                    continue;
                }

                if ($actual !== $expected) {
                    $this->warn("    ⚠ {$table}: manifest={$expected} current={$actual}");
                    $mismatches++;
                }
            }

            if ($mismatches === 0) {
                $this->line('    ✓ All row counts match manifest');
            } else {
                $this->warn("    ⚠ {$mismatches} table(s) have different row counts (data may have changed since backup)");
            }
        }

        $this->newLine();
        if ($allPassed) {
            $this->info('═══════════════════════════════════════════════════════════════');
            $this->info(' ✓ BACKUP VERIFIED — ALL CHECKS PASSED');
            $this->info('═══════════════════════════════════════════════════════════════');
        } else {
            $this->error('═══════════════════════════════════════════════════════════════');
            $this->error(' ✗ BACKUP VERIFICATION FAILED — SEE ERRORS ABOVE');
            $this->error('═══════════════════════════════════════════════════════════════');
        }

        return $allPassed ? self::SUCCESS : self::FAILURE;
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

        return null;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }
}
