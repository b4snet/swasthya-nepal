<?php

namespace App\Console\Commands;

use App\Models\DomainEvent;
use App\Services\Events\EventProcessor;
use Illuminate\Console\Command;

/**
 * Process pending domain events from the outbox (Phase 33).
 *
 * Polls the domain_events table for pending events, resolves
 * their handler, and processes them with retry/dead-letter support.
 *
 * Usage:
 *   php artisan outbox:process           # process one batch
 *   php artisan outbox:process --once    # process one batch and exit
 *   php artisan outbox:process --sleep=5 # poll every 5 seconds
 *   php artisan outbox:process --dry-run # show pending without processing
 *   php artisan outbox:process --limit=100 # process up to 100 events
 */
class ProcessOutbox extends Command
{
    protected $signature = 'outbox:process
                            {--once : Process one batch and exit}
                            {--sleep=2 : Seconds between polls when running continuously}
                            {--limit=50 : Maximum events per batch}
                            {--dry-run : Show pending events without processing}';

    protected $description = 'Process pending domain events from the outbox';

    public function handle(): int
    {
        $sleep = (int) $this->option('sleep');
        $limit = (int) $this->option('limit');
        $once = $this->option('once');
        $dryRun = $this->option('dry-run');

        $this->info("Outbox processor started (limit={$limit}, sleep={$sleep}s)");

        do {
            $processed = $this->processBatch($limit, $dryRun);

            if ($dryRun) {
                return self::SUCCESS;
            }

            if ($once || $processed === 0) {
                break;
            }

            sleep($sleep);
        } while (true);

        return self::SUCCESS;
    }

    private function processBatch(int $limit, bool $dryRun): int
    {
        $events = DomainEvent::forProcessing()->limit($limit)->get();

        if ($dryRun) {
            $this->table(
                ['ID', 'Type', 'Aggregate', 'Attempts', 'Status'],
                $events->map(fn (DomainEvent $e) => [
                    substr($e->getKey(), 0, 8),
                    $e->event_type,
                    $e->aggregate_type,
                    $e->attempt_count,
                    $e->status,
                ])->toArray()
            );
            return $events->count();
        }

        $processed = 0;

        foreach ($events as $event) {
            try {
                $event->markProcessing();
                EventProcessor::process($event);
                $processed++;

                $this->line("  ✓ {$event->event_type} ({$event->aggregate_type}:{$event->aggregate_id})");
            } catch (\Throwable $e) {
                $event->markFailed($e->getMessage());

                $this->error("  ✗ {$event->event_type}: {$e->getMessage()}");

                if ($event->isDead()) {
                    $this->warn("    → Dead letter (attempt {$event->attempt_count}/{$event->max_attempts})");
                }
            }
        }

        if ($processed > 0) {
            $this->info("Processed {$processed} event(s)");
        }

        return $processed;
    }
}
