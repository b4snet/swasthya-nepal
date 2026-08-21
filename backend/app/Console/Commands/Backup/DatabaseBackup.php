<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Create a verified database backup using pg_dump.
 *
 * Produces:
 *   - .dump file (pg_dump custom format)
 *   - .manifest.json (table counts, checksums, metadata)
 *   - .sha256 (checksum of the dump file)
 */
final class DatabaseBackup extends Command
{
    protected $signature = 'backup:database
        {--dir=../backups : Backup output directory}
        {--compress : Enable gzip compression (slower, smaller)}
        {--schema-only : Schema only — no data}
        {--no-owner : Omit pg_restore owner directives}
        {--label= : Optional human-readable label}';

    protected $description = 'Create a verified database backup using pg_dump';

    public function handle(): int
    {
        $pgBin = $this->findPgBin();
        if ($pgBin === null) {
            $this->error('pg_dump not found. Install PostgreSQL or set PG_BIN_PATH.');

            return self::FAILURE;
        }

        $dbConfig = config('database.connections.pgsql');
        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 5432;
        $database = $dbConfig['database'] ?? 'swasthya';
        $username = $dbConfig['username'] ?? 'postgres';
        $password = $dbConfig['password'] ?? '';

        $dir = $this->option('dir');
        $label = $this->option('label');
        $timestamp = now()->format('Y-m-d_H-i-s');
        $labelSuffix = $label ? "_{$label}" : '';
        $filename = "swasthya_{$timestamp}{$labelSuffix}";
        $dumpPath = "{$dir}/{$filename}.dump";
        $manifestPath = "{$dir}/{$filename}.manifest.json";
        $checksumPath = "{$dir}/{$filename}.sha256";

        // Ensure backup directory exists
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(" DATABASE BACKUP — {$database}");
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Host:      {$host}:{$port}");
        $this->line("  Database:  {$database}");
        $this->line("  Output:    {$dumpPath}");
        $this->newLine();

        // ── Step 1: pg_dump ──
        $this->info('  [1/4] Running pg_dump...');
        $start = microtime(true);

        $pgDump = escapeshellarg(rtrim($pgBin, '/\\').'/pg_dump.exe');
        $cmd = $pgDump
            .' -h '.escapeshellarg($host)
            .' -p '.escapeshellarg((string) $port)
            .' -U '.escapeshellarg($username)
            .' -d '.escapeshellarg($database)
            .' -Fc'
            .' --no-owner'
            .' --verbose'
            .' -f '.escapeshellarg($dumpPath);

        if ($this->option('compress')) {
            $cmd .= ' -Z 6';
        }
        if ($this->option('schema-only')) {
            $cmd .= ' --schema-only';
        }

        $output = [];
        $exitCode = 0;
        if ($password) {
            putenv('PGPASSWORD='.$password);
        }
        exec($cmd.' 2>&1', $output, $exitCode);

        $elapsed = round(microtime(true) - $start, 2);

        if ($exitCode !== 0) {
            $this->error("  pg_dump failed (exit code {$exitCode})");
            foreach (array_slice($output, -10) as $line) {
                $this->error("    {$line}");
            }

            return self::FAILURE;
        }

        $dumpSize = file_exists($dumpPath) ? filesize($dumpPath) : 0;
        $this->line("    ✓ pg_dump completed in {$elapsed}s — ".$this->formatBytes($dumpSize));

        // ── Step 2: Capture table counts for manifest ──
        $this->info('  [2/4] Capturing table counts...');
        $tables = $this->getTableCounts();
        $tableCount = count($tables);
        $totalRows = array_sum(array_column($tables, 'row_count'));
        $this->line("    ✓ {$tableCount} tables, {$totalRows} total rows");

        // ── Step 3: Generate SHA-256 checksum ──
        $this->info('  [3/4] Generating SHA-256 checksum...');
        $hash = hash_file('sha256', $dumpPath);
        file_put_contents($checksumPath, "{$hash}  ".basename($dumpPath)."\n");
        $this->line("    ✓ {$hash}");

        // ── Step 4: Write manifest ──
        $this->info('  [4/4] Writing manifest...');
        $manifest = [
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'label' => $label,
            'database' => [
                'host' => $host,
                'port' => $port,
                'database' => $database,
            ],
            'dump' => [
                'path' => realpath($dumpPath),
                'filename' => basename($dumpPath),
                'size_bytes' => $dumpSize,
                'sha256' => $hash,
                'format' => 'pg_dump_custom',
                'pg_dump_version' => $this->getPgDumpVersion($pgBin),
            ],
            'schema' => [
                'table_count' => $tableCount,
                'total_rows' => $totalRows,
                'tables' => $tables,
            ],
            'checks' => [
                'dump_file_exists' => file_exists($dumpPath),
                'dump_file_size_gt_zero' => $dumpSize > 0,
                'checksum_file_exists' => file_exists($checksumPath),
                'manifest_complete' => true,
            ],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->line('    ✓ Manifest written');
        $this->newLine();

        // ── Summary ──
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' BACKUP COMPLETE');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Dump:       {$dumpPath}");
        $this->line("  Manifest:   {$manifestPath}");
        $this->line("  Checksum:   {$checksumPath}");
        $this->line('  Size:       '.$this->formatBytes($dumpSize));
        $this->line("  Tables:     {$tableCount}");
        $this->line("  Rows:       {$totalRows}");
        $this->line("  SHA-256:    {$hash}");
        $this->info('═══════════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    private function getTableCounts(): array
    {
        $tables = DB::select("
            SELECT tablename
            FROM pg_tables
            WHERE schemaname = 'public'
            ORDER BY tablename
        ");

        $result = [];
        foreach ($tables as $table) {
            $count = (int) DB::select("SELECT COUNT(*) as cnt FROM public.\"{$table->tablename}\"")[0]->cnt;
            $result[] = [
                'table' => $table->tablename,
                'row_count' => $count,
            ];
        }

        return $result;
    }

    private function findPgBin(): ?string
    {
        // 1. Environment variable
        $env = getenv('PG_BIN_PATH');
        if ($env && is_dir($env)) {
            return $env;
        }

        // 2. Toolchain
        $toolchain = base_path().'/../.toolchain/pgsql/pgsql/bin';
        if (is_dir($toolchain)) {
            return $toolchain;
        }

        // 3. System PATH
        $output = [];
        exec('which pg_dump 2>/dev/null || where pg_dump 2>/dev/null', $output, $exit);
        if ($exit === 0 && ! empty($output[0])) {
            return dirname($output[0]);
        }

        return null;
    }

    private function getPgDumpVersion(string $pgBin): string
    {
        $output = [];
        exec(escapeshellarg(rtrim($pgBin, '/\\').'/pg_dump.exe').' --version 2>&1', $output);

        return $output[0] ?? 'unknown';
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
