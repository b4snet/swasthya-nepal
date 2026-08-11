<?php

use Illuminate\Support\Str;

/**
 * Structured logging foundation (MASTER_RULES.md §18, OBSERVABILITY.md §17):
 * one JSON line per request carrying the ids and operational facts — and
 * never request bodies, response bodies, or headers (no PHI, no secrets).
 *
 * These tests assert on the real 'json' channel output, so they also prove
 * the JSON formatter configuration actually produces parseable lines.
 */
function logEntriesFor(string $correlationId): array
{
    $logFile = storage_path('logs/laravel.log');
    if (! is_file($logFile)) {
        return [];
    }

    return collect(file($logFile))
        ->map(fn (string $line): ?array => json_decode($line, true))
        ->filter(fn (?array $entry): bool => is_array($entry)
            && ($entry['context']['correlation_id'] ?? null) === $correlationId)
        ->values()
        ->all();
}

it('logs a structured request.completed line with ids and operational facts', function () {
    $correlation = 'gesture-'.Str::uuid();

    $this->getJson('/api/v1/health/live', ['X-Correlation-Id' => $correlation]);

    $entries = logEntriesFor($correlation);
    $entry = collect($entries)->first(fn (array $e): bool => $e['message'] === 'request.completed');

    expect($entry)->not->toBeNull()
        ->and($entry['context']['request_id'] ?? null)->toBeString()
        ->and($entry['context'])->toMatchArray([
            'correlation_id' => $correlation,
            'method' => 'GET',
            'path' => 'api/v1/health/live',
            'status' => 200,
        ])
        ->and($entry['context'])->toHaveKeys(['duration_ms']);
});

it('never logs request bodies, response bodies, or headers', function () {
    $correlation = 'gesture-'.Str::uuid();

    $this->postJson('/api/v1/anything', ['password' => 'hunter2-secret', 'note' => 'patient said X'], [
        'X-Correlation-Id' => $correlation,
    ])->assertNotFound();

    $entry = collect(logEntriesFor($correlation))
        ->first(fn (array $e): bool => $e['message'] === 'request.completed');

    expect($entry)->not->toBeNull()
        ->and($entry['context'])->not->toHaveKeys(['body', 'headers', 'query'])
        ->and(json_encode($entry))->not->toContain('hunter2-secret');
});

it('logs exactly one request.completed line per request, success or error', function () {
    // Success path.
    $okCorrelation = 'gesture-ok-'.Str::uuid();
    $this->getJson('/api/v1/health/live', ['X-Correlation-Id' => $okCorrelation])->assertOk();

    $okLines = collect(logEntriesFor($okCorrelation))
        ->filter(fn (array $e): bool => $e['message'] === 'request.completed')
        ->count();
    expect($okLines)->toBe(1);

    // Error path — the exception response must not double-log.
    $errCorrelation = 'gesture-err-'.Str::uuid();
    $this->postJson('/api/v1/anything', ['payload' => 'x'], [
        'X-Correlation-Id' => $errCorrelation,
    ])->assertNotFound();

    $errLines = collect(logEntriesFor($errCorrelation))
        ->filter(fn (array $e): bool => $e['message'] === 'request.completed')
        ->count();
    expect($errLines)->toBe(1);
});
