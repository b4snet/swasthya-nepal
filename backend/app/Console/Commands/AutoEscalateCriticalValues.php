<?php

namespace App\Console\Commands;

use App\Models\CriticalValueEvent;
use App\Models\Staff;
use App\Services\Notification\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Automatically escalate unacknowledged critical lab values after a timeout.
 *
 * Usage:
 *   php artisan critical-values:auto-escalate [--timeout_minutes=30] [--dry-run]
 *
 * For every triggered critical_value_event older than the timeout, this command:
 *   1. Escalates it (triggered → escalated) with a system supervisor as escalator
 *   2. Sends an in-app notification to the ordering clinician
 *   3. Logs the escalation in the audit trail
 *
 * Run via scheduler every 15 minutes:
 *   $schedule->command('critical-values:auto-escalate')->everyFifteenMinutes();
 */
final class AutoEscalateCriticalValues extends Command
{
    protected $signature = 'critical-values:auto-escalate
        {--timeout_minutes=30 : Minutes before auto-escalation}
        {--dry-run : Show what would be escalated without changing data}';

    protected $description = 'Auto-escalate unacknowledged critical lab values after timeout';

    public function handle(NotificationService $notifications): int
    {
        $timeoutMinutes = (int) $this->option('timeout_minutes');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subMinutes($timeoutMinutes);

        // Find all triggered (unacknowledged, un-escalated) events older than cutoff
        $events = CriticalValueEvent::query()
            ->where('status', CriticalValueEvent::STATUS_TRIGGERED)
            ->where('detected_at', '<=', $cutoff)
            ->with(['item.test', 'patient', 'target'])
            ->orderBy('detected_at')
            ->get();

        if ($events->isEmpty()) {
            $this->info('No critical values require escalation.');
            return 0;
        }

        $this->info("Found {$events->count()} critical value(s) older than {$timeoutMinutes} minutes.");

        if ($dryRun) {
            foreach ($events as $event) {
                $testName = $event->item?->test?->name ?? 'Unknown test';
                $patientName = $event->patient?->first_name . ' ' . $event->patient?->last_name;
                $this->line("  - {$testName} (patient: {$patientName}, detected: {$event->detected_at})");
            }
            $this->info('Dry run — no changes made.');
            return 0;
        }

        $escalated = 0;

        foreach ($events as $event) {
            // Find a supervisor (any active doctor in the same tenant) as the escalator
            $supervisor = Staff::query()
                ->where('tenant_id', $event->tenant_id)
                ->where('status', 'active')
                ->where('role_code', 'doctor')
                ->where('id', '!=', $event->target_staff_id)
                ->first();

            // Fallback: use any active staff
            if (! $supervisor) {
                $supervisor = Staff::query()
                    ->where('tenant_id', $event->tenant_id)
                    ->where('status', 'active')
                    ->where('id', '!=', $event->target_staff_id)
                    ->first();
            }

            if (! $supervisor) {
                $this->warn("  Skipping event {$event->getKey()}: no available supervisor to escalate.");
                continue;
            }

            $result = DB::table('critical_value_events')
                ->where('id', $event->getKey())
                ->where('status', CriticalValueEvent::STATUS_TRIGGERED)
                ->where('lock_version', $event->lock_version)
                ->update([
                    'status' => CriticalValueEvent::STATUS_ESCALATED,
                    'escalated_by_staff_id' => $supervisor->getKey(),
                    'escalated_at' => now(),
                    'lock_version' => $event->lock_version + 1,
                    'updated_at' => now(),
                ]);

            if ($result !== 1) {
                $this->warn("  Skipped event {$event->getKey()}: concurrent modification.");
                continue;
            }

            // Send in-app notification to the target clinician
            if ($event->target?->user_id) {
                $testName = $event->item?->test?->name ?? 'Unknown test';
                $patientName = trim(($event->patient?->first_name ?? '') . ' ' . ($event->patient?->last_name ?? ''));

                $notifications->createNotification(
                    tenantId: $event->tenant_id,
                    userId: $event->target->user_id,
                    type: 'critical_value_escalated',
                    channel: 'in_app',
                    payload: [
                        'subject' => 'URGENT: Critical value auto-escalated',
                        'body' => "{$testName} for patient {$patientName} was not acknowledged within {$timeoutMinutes} minutes and has been auto-escalated.",
                        'critical_value_event_id' => $event->getKey(),
                        'patient_id' => $event->patient_id,
                    ],
                    sensitive: true,
                    patientId: $event->patient_id,
                );
            }

            $testName = $event->item?->test?->name ?? 'Unknown test';
            $this->line("  Escalated: {$testName} (event {$event->getKey()})");
            $escalated++;
        }

        $this->info("Auto-escalated {$escalated} critical value(s).");
        return 0;
    }
}
