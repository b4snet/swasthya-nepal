<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionMapper;
use App\Support\ErrorCodes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The mapper turns every Throwable into the contract's error envelope
 * (API_CONTRACTS.md §8, MASTER_RULES.md §17). Each mapping is a contract
 * promise — these tests are the regression net.
 */
function apiRequest(): Request
{
    $request = Request::create('/api/v1/anything');
    $request->attributes->set('request_id', 'req-123');
    $request->attributes->set('correlation_id', 'corr-456');

    return $request;
}

function assertMapped(Throwable $exception, int $status, string $code): void
{
    $response = ApiExceptionMapper::toResponse($exception, apiRequest());
    expect($response)->not->toBeNull()
        ->and($response->getStatusCode())->toBe($status)
        ->and($response->getData(true)['error']['code'])->toBe($code)
        ->and($response->getData(true)['error']['correlationId'])->toBe('corr-456');
}

it('maps validation exceptions to 422 VALIDATION_ERROR with field details', function () {
    // A real validator (no mocking): make() already ran it, so failed()
    // returns the actual failing rules.
    $validator = app('validator')->make(
        ['email' => '', 'age' => 'abc'],
        ['email' => ['required'], 'age' => ['integer']],
    );

    $exception = new ValidationException($validator);

    $response = ApiExceptionMapper::toResponse($exception, apiRequest());

    expect($response->getStatusCode())->toBe(422)
        ->and($response->getData(true)['error'])->toMatchArray([
            'code' => 'VALIDATION_ERROR',
            'message' => '2 field(s) failed validation.',
            'details' => [
                ['field' => 'email', 'code' => 'REQUIRED', 'message' => 'The email field is required.'],
                ['field' => 'age', 'code' => 'INVALID_TYPE', 'message' => 'The age field must be an integer.'],
            ],
        ]);
});

it('maps authentication failures to 401 INVALID_TOKEN', function () {
    assertMapped(new AuthenticationException('Unauthenticated.'), 401, ErrorCodes::INVALID_TOKEN);
});

it('maps authorization failures to 403 FORBIDDEN', function () {
    assertMapped(new AuthorizationException('This action is unauthorized.'), 403, ErrorCodes::FORBIDDEN);
});

it('maps missing models and unknown routes to 404 NOT_FOUND', function () {
    assertMapped(new ModelNotFoundException, 404, ErrorCodes::NOT_FOUND);
    assertMapped(new NotFoundHttpException, 404, ErrorCodes::NOT_FOUND);
});

it('maps rate limiting to 429 RATE_LIMITED with Retry-After', function () {
    $response = ApiExceptionMapper::toResponse(
        new ThrottleRequestsException('Too Many Attempts.', headers: ['Retry-After' => '60']),
        apiRequest(),
    );

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe('60')
        ->and($response->getData(true)['error']['code'])->toBe(ErrorCodes::RATE_LIMITED);
});

it('maps 503 to SERVICE_UNAVAILABLE', function () {
    assertMapped(new ServiceUnavailableHttpException, 503, ErrorCodes::SERVICE_UNAVAILABLE);
});

it('maps unknown exceptions to a generic 500 SERVER_ERROR without leaking internals', function () {
    $response = ApiExceptionMapper::toResponse(
        new RuntimeException('secret internal detail: connection string leaked'),
        apiRequest(),
    );

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getData(true)['error']['code'])->toBe(ErrorCodes::SERVER_ERROR)
        ->and($response->getData(true)['error']['message'])->toBe('An unexpected error occurred.')
        ->and(json_encode($response->getData(true)))->not->toContain('connection string leaked');
});

it('maps typed ApiExceptions to their own code and status', function () {
    assertMapped(
        new ApiException(ErrorCodes::CONFLICT, 'The slot is already booked.', 409),
        409,
        ErrorCodes::CONFLICT,
    );
});

it('returns null for non-API requests so the framework default applies', function () {
    $request = Request::create('/some-web-route');
    $request->headers->set('Accept', 'text/html');

    expect(ApiExceptionMapper::toResponse(new NotFoundHttpException, $request))->toBeNull();
});
