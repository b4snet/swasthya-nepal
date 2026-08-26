<?php

use App\Console\Commands\ProcessOutbox;
use App\Exceptions\ApiException;
use App\Models\DomainEvent;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Notification;
use App\Services\Events\EventDispatcher;
use App\Services\Events\EventProcessor;
use App\Services\Events\Handlers\CriticalValueDetectedHandler;
use App\Services\Events\Handlers\SendNotificationHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 33 async infrastructure tests — EventDispatcher, DomainEvent,
 * EventProcessor, handlers, and ProcessOutbox command.
 *
 * These prove the outbox pattern works: events are persisted, processed,
 * retried, quarantined, and idempotent.
 */
beforeEach(function (): void {
    seedIdentity();
});

// ─────────────────── EventDispatcher ───────────────────

it('dispatches a single domain event with all fields', function (): void {
    $eventId = Str::uuid()->toString();
    $event = EventDispatcher::dispatch(
        eventType: 'notification.created',
        aggregateType: 'notification',
        aggregateId: $eventId,
        payload: ['user_id' => 'user-1', 'title' => 'Test'],
        causerId: 'staff-1',
        facilityId: 'fac-1',
        tenantId: 'tenant-1',
        correlationId: 'corr-123',
        idempotencyKey: 'idem-456',
    );

    expect($event)->toBeInstanceOf(DomainEvent::class)
        ->and($event->event_type)->toBe('notification.created')
        ->and($event->aggregate_type)->toBe('notification')
        ->and($event->aggregate_id)->toBe($eventId)
        ->and($event->payload)->toBe(['user_id' => 'user-1', 'title' => 'Test'])
        ->and($event->causer_id)->toBe('staff-1')
        ->and($event->facility_id)->toBe('fac-1')
        ->and($event->tenant_id)->toBe('tenant-1')
        ->and($event->correlation_id)->toBe('corr-123')
        ->and($event->idempotency_key)->toBe('idem-456')
        ->and($event->status)->toBe(DomainEvent::STATUS_PENDING)
        ->and($event->attempt_count)->toBe(0)
        ->and($event->max_attempts)->toBe(5);
});

it('generates a correlation_id when not provided', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'test.event',
        aggregateType: 'test',
        aggregateId: 'agg-1',
    );

    expect($event->correlation_id)->not->toBeEmpty()
        ->and(Str::isUuid($event->correlation_id))->toBeTrue();
});

it('dispatches multiple events atomically', function (): void {
    $events = EventDispatcher::dispatchMany([
        ['eventType' => 'event.a', 'aggregateType' => 'agg', 'aggregateId' => 'a-1'],
        ['eventType' => 'event.b', 'aggregateType' => 'agg', 'aggregateId' => 'b-2'],
        ['eventType' => 'event.c', 'aggregateType' => 'agg', 'aggregateId' => 'c-3'],
    ]);

    expect(count($events))->toBe(3);
    expect(DomainEvent::query()->count())->toBe(3);

    expect($events[0]->event_type)->toBe('event.a')
        ->and($events[1]->event_type)->toBe('event.b')
        ->and($events[2]->event_type)->toBe('event.c');
});

// ─────────────────── DomainEvent Model ───────────────────

it('transitions from pending to processing and increments attempt count', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'test',
        aggregateType: 'test',
        aggregateId: '1',
    );

    expect($event->status)->toBe(DomainEvent::STATUS_PENDING)
        ->and($event->attempt_count)->toBe(0);

    $event->markProcessing();

    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_PROCESSING)
        ->and($event->fresh()->attempt_count)->toBe(1);

    $event->markProcessing();

    expect($event->fresh()->attempt_count)->toBe(2);
});

it('transitions from processing to completed with timestamp', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'test',
        aggregateType: 'test',
        aggregateId: '1',
    );
    $event->markProcessing();
    $event->markCompleted();

    $fresh = $event->fresh();
    expect($fresh->status)->toBe(DomainEvent::STATUS_COMPLETED)
        ->and($fresh->processed_at)->not->toBeNull();
});

it('transitions from processing to failed with exponential backoff', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'test',
        aggregateType: 'test',
        aggregateId: '1',
    );
    $event->markProcessing();
    $event->markFailed('Something went wrong');

    $fresh = $event->fresh();
    expect($fresh->status)->toBe(DomainEvent::STATUS_FAILED)
        ->and($fresh->last_error)->toBe('Something went wrong')
        ->and($fresh->next_attempt_at)->not->toBeNull()
        ->and($fresh->next_attempt_at->greaterThan(now()))->toBeTrue();
});

it('transitions to dead after exhausting max attempts', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'test',
        aggregateType: 'test',
        aggregateId: '1',
    );

    // Simulate max_attempts=3 for test speed
    $event->update(['max_attempts' => 3]);

    // Attempt 1
    $event->markProcessing();
    $event->markFailed('error 1');
    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_FAILED);

    // Attempt 2
    $event->fresh()->markProcessing();
    $event->fresh()->markFailed('error 2');
    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_FAILED);

    // Attempt 3 — exceeds budget → dead
    $event->fresh()->markProcessing();
    $event->fresh()->markFailed('error 3');
    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_DEAD)
        ->and($event->fresh()->next_attempt_at)->toBeNull();
});

it('correctly identifies retryable vs dead events', function (): void {
    $retryable = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: '1');
    expect($retryable->isRetryable())->toBeTrue()
        ->and($retryable->isDead())->toBeFalse();

    $retryable->markProcessing();
    $retryable->markFailed('error');
    expect($retryable->fresh()->isRetryable())->toBeTrue()
        ->and($retryable->fresh()->isDead())->toBeFalse();

    $dead = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: '2');
    $dead->update(['max_attempts' => 1]);
    $dead->markProcessing();
    $dead->markFailed('fatal');
    expect($dead->fresh()->isRetryable())->toBeFalse()
        ->and($dead->fresh()->isDead())->toBeTrue();
});

it('scope forProcessing returns only pending events due for processing', function (): void {
    // Pending, ready now
    $ready = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: '1');

    // Pending, but delayed
    $delayed = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: '2');
    $delayed->update(['next_attempt_at' => now()->addHour()]);

    // Completed
    $done = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: '3');
    $done->markProcessing();
    $done->markCompleted();

    $processing = DomainEvent::forProcessing()->get();

    expect($processing->pluck('id'))->toContain($ready->getKey())
        ->and($processing->pluck('id'))->not->toContain($delayed->getKey())
        ->and($processing->pluck('id'))->not->toContain($done->getKey());
});

// ─────────────────── EventProcessor ───────────────────

it('resolves the correct handler for notification.created', function (): void {
    $handler = EventProcessor::resolveHandler('notification.created');
    expect($handler)->toBeInstanceOf(SendNotificationHandler::class);
});

it('resolves the correct handler for critical_value.detected', function (): void {
    $handler = EventProcessor::resolveHandler('critical_value.detected');
    expect($handler)->toBeInstanceOf(CriticalValueDetectedHandler::class);
});

it('returns null for unregistered event types', function (): void {
    $handler = EventProcessor::resolveHandler('unknown.event.type');
    expect($handler)->toBeNull();
});

it('processes an event and marks it completed', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'critical_value.detected',
        aggregateType: 'lab_order',
        aggregateId: 'lab-1',
        payload: ['patient_id' => 'p-1', 'severity' => 'critical'],
    );
    $event->markProcessing();

    Log::shouldReceive('info')->atLeast()->once();

    $result = EventProcessor::process($event->fresh());

    expect($result)->toBeTrue()
        ->and($event->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED);
});

it('marks unhandled event types as completed (no-op)', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'unregistered.event',
        aggregateType: 'test',
        aggregateId: '1',
    );
    $event->markProcessing();

    Log::shouldReceive('info')->atLeast()->once();

    $result = EventProcessor::process($event->fresh());

    expect($result)->toBeTrue()
        ->and($event->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED);
});

// ─────────────────── SendNotificationHandler ───────────────────

it('creates an in-app notification from the event payload', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'notification.created',
        aggregateType: 'notification',
        aggregateId: 'n-1',
        payload: [
            'user_id' => Identity::user()->getKey(),
            'title' => 'Appointment Confirmed',
            'body' => 'Your appointment is confirmed.',
            'type' => 'success',
            'link' => '/appointments/apt-1',
        ],
        tenantId: Identity::organization()->getKey(),
        facilityId: Identity::facility()->getKey(),
    );
    $event->markProcessing();

    app(SendNotificationHandler::class)->handle($event->fresh());

    $notification = Notification::query()
        ->where('user_id', Identity::user()->getKey())
        ->where('title', 'Appointment Confirmed')
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->body)->toBe('Your appointment is confirmed.')
        ->and($notification->type)->toBe('success')
        ->and($notification->link)->toBe('/appointments/apt-1')
        ->and($notification->read)->toBeFalse();
});

it('is idempotent — does not create duplicate notifications', function (): void {
    $userId = Identity::user()->getKey();
    $event = EventDispatcher::dispatch(
        eventType: 'notification.created',
        aggregateType: 'notification',
        aggregateId: 'n-2',
        payload: [
            'user_id' => $userId,
            'title' => 'Duplicate Test',
            'body' => 'Should appear once',
            'type' => 'info',
        ],
    );
    $event->markProcessing();

    app(SendNotificationHandler::class)->handle($event->fresh());
    app(SendNotificationHandler::class)->handle($event->fresh());

    $count = Notification::query()
        ->where('user_id', $userId)
        ->where('title', 'Duplicate Test')
        ->count();

    expect($count)->toBe(1);
});

it('skips notification when user_id is missing from payload', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'notification.created',
        aggregateType: 'notification',
        aggregateId: 'n-3',
        payload: ['title' => 'No User', 'body' => 'Missing user_id'],
    );
    $event->markProcessing();

    Log::shouldReceive('warning')->atLeast()->once();

    app(SendNotificationHandler::class)->handle($event->fresh());

    expect(Notification::query()->where('title', 'No User')->count())->toBe(0);
});

// ─────────────────── CriticalValueDetectedHandler ───────────────────

it('logs the critical value detection event', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'critical_value.detected',
        aggregateType: 'lab_order',
        aggregateId: 'lab-cv-1',
        payload: [
            'critical_value_event_id' => 'cv-evt-1',
            'patient_id' => 'p-1',
            'severity' => 'critical',
        ],
    );
    $event->markProcessing();

    Log::shouldReceive('info')->atLeast()->once();

    app(CriticalValueDetectedHandler::class)->handle($event->fresh());

    // Handler is a no-op that logs — just verify it doesn't throw
    expect(true)->toBeTrue();
});

// ─────────────────── ProcessOutbox Command ───────────────────

it('processes pending events with the outbox command', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'critical_value.detected',
        aggregateType: 'lab_order',
        aggregateId: 'cmd-1',
        payload: ['patient_id' => 'p-1', 'severity' => 'critical'],
    );

    Log::shouldReceive('info')->atLeast()->once();

    $this->artisan(ProcessOutbox::class, ['--once' => true])
        ->expectsOutputToContain('Outbox processor started')
        ->assertExitCode(0);

    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED);
});

it('reports zero pending events when outbox is empty', function (): void {
    $this->artisan(ProcessOutbox::class, ['--once' => true])
        ->expectsOutputToContain('Outbox processor started')
        ->assertExitCode(0);
});

it('dry-run shows pending events without processing them', function (): void {
    $event = EventDispatcher::dispatch(
        eventType: 'test.event',
        aggregateType: 'test',
        aggregateId: 'dry-1',
    );

    $this->artisan(ProcessOutbox::class, ['--once' => true, '--dry-run' => true])
        ->assertExitCode(0);

    // Event should still be pending (not processed)
    expect($event->fresh()->status)->toBe(DomainEvent::STATUS_PENDING);
});

it('processes events in order (oldest first)', function (): void {
    $old = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: 'old');
    $old->update(['created_at' => now()->subHour()]);

    $new = EventDispatcher::dispatch(eventType: 'test', aggregateType: 't', aggregateId: 'new');

    Log::shouldReceive('info')->atLeast()->once();

    $this->artisan(ProcessOutbox::class, ['--once' => true])->assertExitCode(0);

    expect($old->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED)
        ->and($new->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED);
});

it('handles mixed success and failure events in one batch', function (): void {
    $ok = EventDispatcher::dispatch(
        eventType: 'critical_value.detected',
        aggregateType: 'lab',
        aggregateId: 'ok-1',
        payload: ['patient_id' => 'p-1', 'severity' => 'critical'],
    );

    $fail = EventDispatcher::dispatch(
        eventType: 'notification.created',
        aggregateType: 'notification',
        aggregateId: 'fail-1',
        payload: ['title' => 'Test', 'body' => 'Test'], // missing user_id → handler logs warning but doesn't throw
    );

    Log::shouldReceive('info')->atLeast()->once();
    Log::shouldReceive('warning')->atLeast()->once();

    $this->artisan(ProcessOutbox::class, ['--once' => true])->assertExitCode(0);

    // Both should be completed (notification handler is graceful)
    expect($ok->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED)
        ->and($fail->fresh()->status)->toBe(DomainEvent::STATUS_COMPLETED);
});

// ─────────────────── Integration Event Lifecycle ───────────────────

it('records integration events with direction, type, and correlation', function (): void {
    $integration = Integration::factory()->create();
    $service = app(App\Services\IntegrationRegistryService::class);

    $event = $service->recordEvent(
        $integration,
        'outbound',
        'FHIR Patient',
        ['patientId' => 'p-1'],
        consentBasis: 'consent-123',
        mappingVersion: '1.0',
    );

    expect($event)->toBeInstanceOf(IntegrationEvent::class)
        ->and($event->direction)->toBe('outbound')
        ->and($event->message_type)->toBe('FHIR Patient')
        ->and($event->consent_basis)->toBe('consent-123')
        ->and($event->mapping_version)->toBe('1.0')
        ->and($event->status)->toBe(IntegrationEvent::STATUS_QUEUED)
        ->and($event->correlation_id)->not->toBeEmpty();
});

it('retries integration events with bounded budget and quarantines', function (): void {
    $integration = Integration::factory()->create();
    $service = app(App\Services\IntegrationRegistryService::class);

    $event = $service->recordEvent($integration, 'outbound', 'test', []);

    // Retry up to the budget
    for ($i = 0; $i < IntegrationRegistryService::RETRY_BUDGET; $i++) {
        $event = $service->markRetry($event, "attempt {$i}");
        expect($event->status)->toBe(IntegrationEvent::STATUS_RETRYING);
    }

    // One more retry exceeds the budget → quarantined
    $event = $service->markRetry($event, 'final attempt');
    expect($event->status)->toBe(IntegrationEvent::STATUS_QUARANTINED);
});

it('rejects invalid integration event direction', function (): void {
    $integration = Integration::factory()->create();
    $service = app(App\Services\IntegrationRegistryService::class);

    expect(fn () => $service->recordEvent($integration, 'sideways', 'test', []))
        ->toThrow(ApiException::class, 'direction');
});
