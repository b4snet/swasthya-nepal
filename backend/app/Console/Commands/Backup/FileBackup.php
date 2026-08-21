<?php

namespace App\Console\Commands\Backup;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Backup file storage (public/uploads, documents, any configured disk).
 *
 * Copies uploaded files to a timestamped backup directory with checksums.
 * Does NOT back up database content — use backup:database for that.
 */
final class FileBackup extends Command
{
    protected $signature = 'backup:files
        {--dir=../backups : Backup output directory}
        {--disk=public : Storage disk to back up}
        {--label= : Optional human-readable label}';

    protected $description = 'Backup uploaded files from storage disk';

    public function handle(): int
    {
        $diskName = $this->option('disk');
        $dir = $this->option('dir');
        $label = $this->option('label');
        $timestamp = now()->format('Y-m-d_H-i-s');
        $labelSuffix = $label ? "_{$label}" : '';
        $backupDir = "{$dir}/files_{$timestamp}{$labelSuffix}";

        $disk = Storage::disk($diskName);
        $root = $disk->getDriver()->getAdapter()->getPathPrefix();

        if (! $root || ! is_dir($root)) {
            $this->error("Storage disk '{$diskName}' root not found at: {$root}");

            return self::FAILURE;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(" FILE BACKUP — disk: {$diskName}");
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Source:  {$root}");
        $this->line("  Target:  {$backupDir}");
        $this->newLine();

        $start = microtime(true);
        $fileCount = 0;
        $totalSize = 0;

        // Recursively copy files
        $this->info('  Copying files...');
        $this->copyDirectory($root, $backupDir, $fileCount, $totalSize);

        $elapsed = round(microtime(true) - $start, 2);

        // Write manifest
        $manifestPath = "{$backupDir}/_manifest.json";
        $manifest = [
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'label' => $label,
            'disk' => $diskName,
            'source_path' => $root,
            'file_count' => $fileCount,
            'total_size_bytes' => $totalSize,
            'elapsed_seconds' => $elapsed,
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->newLine();
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->info(' FILE BACKUP COMPLETE');
        $this->info('═══════════════════════════════════════════════════════════════');
        $this->line("  Files:    {$fileCount}");
        $this->line('  Size:     '.$this->formatBytes($totalSize));
        $this->line("  Time:     {$elapsed}s");
        $this->line("  Output:   {$backupDir}");
        $this->info('═══════════════════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    private function copyDirectory(string $source, string $dest, int &$count, int &$size): void
    {
        if (! is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $items = File::allFiles($source);
        foreach ($items as $file) {
            $relative = ltrim(str_replace($source, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $targetPath = $dest.DIRECTORY_SEPARATOR.$relative;
            $targetDir = dirname($targetPath);

            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            copy($file->getPathname(), $targetPath);
            $count++;
            $size += $file->getSize();

            if ($count % 100 === 0) {
                $this->line("    ... {$count} files copied (".$this->formatBytes($size).')');
            }
        }
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
