<?php

namespace App\Services\Events;

use App\Models\DomainEvent;
use Illuminate\Support\Facades\Log;

/**
 * Domain event processor — resolves and executes event handlers (Phase 33).
 *
 * Maps event types to handler classes. Each handler implements
 * the EventHandlerInterface contract.
 *
 * Handler resolution:
 *   notification.created → NotificationCreatedHandler
 *   appointment.confirmed → AppointmentConfirmedHandler
 *   prescription.dispensed → PrescriptionDispensedHandler
 *
 * If no handler exists for an event type, the event is marked completed
 * (no-op). This prevents unhandled events from entering dead-letter.
 */
final class EventProcessor
{
    /**
     * Process a single domain event.
     *
     * @throws \Throwable
     */
    public static function process(DomainEvent $event): bool
    {
        $handler = static::resolveHandler($event->event_type);

        if ($handler === null) {
            // No handler registered — mark as completed (no-op).
            Log::info("No handler for event type: {$event->event_type}", [
                'event_id' => $event->getKey(),
            ]);
            $event->markCompleted();
            return true;
        }

        $handler->handle($event);
        $event->markCompleted();
        return true;
    }

    /**
     * Resolve the handler class for an event type.
     */
    public static function resolveHandler(string $eventType): ?EventHandlerInterface
    {
        $handlers = static::handlerMap();
        $handlerClass = $handlers[$eventType] ?? null;

        if ($handlerClass === null) {
            return null;
        }

        return app($handlerClass);
    }

    /**
     * Registry of event type → handler class mappings.
     *
     * Add new event handlers here. The pattern is:
     *   'event.type.name' => HandlerClass::class,
     */
    public static function handlerMap(): array
    {
        return [
            'notification.created' => Handlers\SendNotificationHandler::class,
            'critical_value.detected' => Handlers\CriticalValueDetectedHandler::class,
        ];
    }
}
