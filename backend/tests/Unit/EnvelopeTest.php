<?php

use App\Support\Envelope;
use Illuminate\Http\Request;

/**
 * The envelope is the API's single response shape (API_CONTRACTS.md §7–8).
 */
it('wraps success responses in the data/meta/links envelope', function () {
    $request = Request::create('/api/v1/health/live');
    $request->attributes->set('request_id', 'req-123');
    $request->attributes->set('correlation_id', 'corr-456');

    $response = Envelope::success(['status' => 'ok'], request: $request);

    $payload = $response->getData(true);
    expect($payload['data'])->toBe(['status' => 'ok'])
        ->and($payload['meta']['context'])->toMatchArray([
            'tenantId' => null,
            'facilityId' => null,
            'branchId' => null,
            'timezone' => config('app.timezone'),
        ])
        ->and($payload['links'])->toBe([]);
});

it('includes the request and correlation ids on every response', function () {
    $request = Request::create('/api/v1/health/live');
    $request->attributes->set('request_id', 'req-123');
    $request->attributes->set('correlation_id', 'corr-456');

    $response = Envelope::success(data: [], request: $request);

    expect($response->headers->get('X-Request-Id'))->toBe('req-123')
        ->and($response->headers->get('X-Correlation-Id'))->toBe('corr-456');
});

it('renders errors in the error envelope with correlation id', function () {
    $request = Request::create('/api/v1/anything');
    $request->attributes->set('request_id', 'req-123');
    $request->attributes->set('correlation_id', 'corr-456');

    $response = Envelope::error(
        code: 'VALIDATION_ERROR',
        message: '2 field(s) failed validation.',
        status: 422,
        details: [['field' => 'name', 'code' => 'REQUIRED', 'message' => 'The name field is required.']],
        request: $request,
    );

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toMatchArray([
            'code' => 'VALIDATION_ERROR',
            'message' => '2 field(s) failed validation.',
            'correlationId' => 'corr-456',
            'details' => [['field' => 'name', 'code' => 'REQUIRED', 'message' => 'The name field is required.']],
        ]);
});

it('omits details from the error envelope when none are provided', function () {
    $request = Request::create('/api/v1/anything');
    $request->attributes->set('request_id', 'req-123');
    $request->attributes->set('correlation_id', 'corr-456');

    $response = Envelope::error('NOT_FOUND', 'Resource not found.', 404, request: $request);

    expect($response->getData(true)['error'])->not->toHaveKey('details');
});
