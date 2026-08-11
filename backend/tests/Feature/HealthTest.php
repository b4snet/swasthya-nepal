<?php

/**
 * Health endpoints (MASTER_RULES.md §20.2): liveness never depends on
 * downstream services; readiness runs real dependency checks and returns
 * 503 with the failing checks when not ready.
 */
it('reports liveness with the success envelope', function () {
    $response = $this->getJson('/api/v1/health/live');

    $response->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonStructure(['data' => ['status', 'time'], 'meta' => ['context'], 'links']);
});

it('reports readiness with a real database check', function () {
    $response = $this->getJson('/api/v1/health/ready');

    $response->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.checks.0.name', 'database')
        ->assertJsonPath('data.checks.0.status', 'ok');
});

it('serves 404 NOT_FOUND in the envelope for unknown API paths', function () {
    $response = $this->getJson('/api/v1/does-not-exist');

    $response->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND')
        ->assertJsonPath('error.message', 'Resource not found.')
        ->assertJsonStructure(['error' => ['code', 'message', 'correlationId']]);
});

it('does not leak internals on a server error', function () {
    // Force an unexpected exception through the API pipeline. The route is
    // registered only inside this test — never in production routes
    // (TESTING_STRATEGY.md §5).
    $this->app['router']->get('api/v1/_probe/server-error', function (): never {
        throw new RuntimeException('internal secret: db://creds@host/database');
    });

    $response = $this->getJson('/api/v1/_probe/server-error');

    $response->assertStatus(500)
        ->assertJsonPath('error.code', 'SERVER_ERROR')
        ->assertJsonPath('error.message', 'An unexpected error occurred.')
        ->assertDontSee('db://creds@host/database');
});
