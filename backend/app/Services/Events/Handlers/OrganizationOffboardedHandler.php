<?php

namespace App\Services\Events\Handlers;

use App\Models\DomainEvent;
use App\Services\Events\EventHandlerInterface;
use Illuminate\Support\Facades\Log;

/**
 * Handles organization.offboarded events (TENANCY.md V2 §13-14).
 *
 * Once a tenant reaches the tombstone (offboarded) state, its data is purged
 * per policy and the organization row is never soft-deleted. This handler is
 * the idempotent marker for that terminal transition: it records the fact so
 * operator/audit tooling can observe that offboarding completed, and as a
 * placeholder for any follow-on work (e.g. revoking remaining platform
 * assignments, queuing a data purge).
 *
 * MUST be idempotent — the same event may be delivered more than once.
 */
final class OrganizationOffboardedHandler implements EventHandlerInterface
{
    public function handle(DomainEvent $event): void
    {
        Log::info('Organization offboarded (terminal state reached)', [
            'event_id' => $event->getKey(),
            'organization_id' => $event->aggregate_id,
            'occurred_at' => $event->created_at?->toIso8601String(),
        ]);
    }
}
