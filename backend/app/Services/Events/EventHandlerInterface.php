<?php

namespace App\Services\Events;

use App\Models\DomainEvent;

/**
 * Contract for domain event handlers (Phase 33).
 *
 * Each handler processes a specific event type and performs
 * idempotent side effects. Handlers MUST be idempotent —
 * the same event may be delivered multiple times.
 */
interface EventHandlerInterface
{
    /**
     * Handle a domain event.
     *
     * @throws \Throwable Handler failure causes event retry.
     */
    public function handle(DomainEvent $event): void;
}
