<?php

/**
 * Request/correlation ID discipline (API_CONTRACTS.md §17–18): X-Request-Id
 * is always server-generated; X-Correlation-Id may be proposed by the client
 * and is echoed. Both appear on success AND error responses.
 */
it('generates a server-side X-Request-Id and ignores a client-supplied one', function () {
    $response = $this->getJson('/api/v1/health/live', ['X-Request-Id' => 'client-forged-id']);

    $response->assertOk();
    expect($response->headers->get('X-Request-Id'))
        ->not->toBe('client-forged-id')
        ->not->toBeNull();
});

it('echoes a valid client-proposed X-Correlation-Id', function () {
    $response = $this->getJson('/api/v1/health/live', ['X-Correlation-Id' => 'gesture-abc-123']);

    $response->assertOk();
    expect($response->headers->get('X-Correlation-Id'))->toBe('gesture-abc-123');
});

it('generates an X-Correlation-Id when the client sends none', function () {
    $response = $this->getJson('/api/v1/health/live');

    $response->assertOk();
    expect($response->headers->get('X-Correlation-Id'))->not->toBeNull();
});

it('rejects malformed correlation ids instead of echoing them', function () {
    $response = $this->getJson('/api/v1/health/live', ['X-Correlation-Id' => 'spaces and <script>']);

    $response->assertOk();
    expect($response->headers->get('X-Correlation-Id'))->not->toBe('spaces and <script>');
});

it('returns both ids on error responses too', function () {
    $response = $this->getJson('/api/v1/health/live', ['X-Correlation-Id' => 'gesture-err-1']);

    $this->app['router']->get('api/v1/_probe/not-found', fn () => abort(404));
    $response = $this->getJson('/api/v1/_probe/not-found', ['X-Correlation-Id' => 'gesture-err-1']);

    $response->assertNotFound();
    expect($response->headers->get('X-Request-Id'))->not->toBeNull()
        ->and($response->headers->get('X-Correlation-Id'))->toBe('gesture-err-1')
        ->and($response->json('error.correlationId'))->toBe('gesture-err-1');
});
