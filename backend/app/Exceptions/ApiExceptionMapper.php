<?php

namespace App\Exceptions;

use App\Support\Envelope;
use App\Support\ErrorCodes;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Exceptions\MissingSignedUrlException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Maps any Throwable to the Swasthya error envelope (API_CONTRACTS.md §8).
 *
 * Rules honored here:
 *  - structured, typed errors — never a bare 500 or a leak of stack traces,
 *    SQL, internal paths, or PHI (MASTER_RULES.md §17.1–17.2);
 *  - every error carries the request's correlation id so a user-reported
 *    error is traceable end-to-end (§17.5);
 *  - unknown exceptions are logged with full detail by report() and rendered
 *    as a generic SERVER_ERROR (details go to logs, never to the client).
 */
final class ApiExceptionMapper
{
    public static function toResponse(Throwable $exception, Request $request): ?JsonResponse
    {
        // Envelope rendering applies to the API surface only.
        if (! self::isApiRequest($request)) {
            return null;
        }

        [$code, $message, $status, $details, $headers] = self::classify($exception);

        return Envelope::error($code, $message, $status, $details, $request, $headers);
    }

    private static function isApiRequest(Request $request): bool
    {
        if ($request->expectsJson()) {
            return true;
        }

        return str_starts_with($request->path(), config('swasthya.api.prefix'));
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: array, 4: array<string, string>}
     */
    private static function classify(Throwable $exception): array
    {
        if ($exception instanceof ApiException) {
            return [$exception->errorCode, $exception->getMessage(), $exception->statusCode, $exception->details, $exception->headers];
        }

        if ($exception instanceof ValidationException) {
            return [
                ErrorCodes::VALIDATION_ERROR,
                sprintf('%d field(s) failed validation.', count($exception->errors())),
                422,
                self::validationDetails($exception),
                [],
            ];
        }

        if ($exception instanceof AuthenticationException) {
            return [ErrorCodes::INVALID_TOKEN, 'Authentication required.', 401, [], []];
        }

        if ($exception instanceof AuthorizationException) {
            return [ErrorCodes::FORBIDDEN, 'You are not authorized to perform this action.', 403, [], []];
        }

        if ($exception instanceof ModelNotFoundException) {
            return [ErrorCodes::NOT_FOUND, 'Resource not found.', 404, [], []];
        }

        if ($exception instanceof NotFoundHttpException) {
            return [ErrorCodes::NOT_FOUND, 'Resource not found.', 404, [], []];
        }

        if ($exception instanceof ThrottleRequestsException) {
            return [ErrorCodes::RATE_LIMITED, 'Too many requests. Try again later.', 429, [], $exception->getHeaders()];
        }

        if ($exception instanceof HttpException) {
            return self::classifyHttpException($exception);
        }

        // Missing/invalid signed URLs are 403s, not 500s.
        if ($exception instanceof InvalidSignatureException || $exception instanceof MissingSignedUrlException) {
            return [ErrorCodes::FORBIDDEN, 'Invalid signature.', 403, [], []];
        }

        // Fallback: never leak internals (MASTER_RULES.md §17.2).
        return [
            ErrorCodes::SERVER_ERROR,
            'An unexpected error occurred.',
            500,
            [],
            [],
        ];
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: array, 4: array<string, string>}
     */
    private static function classifyHttpException(HttpException $exception): array
    {
        $status = $exception->getStatusCode();
        $headers = $exception->getHeaders();

        [$code, $message] = match ($status) {
            400 => [ErrorCodes::INVALID_REQUEST, 'The request is malformed.'],
            401 => [ErrorCodes::INVALID_TOKEN, 'Authentication required.'],
            403 => [ErrorCodes::FORBIDDEN, 'You are not authorized to perform this action.'],
            404 => [ErrorCodes::NOT_FOUND, 'Resource not found.'],
            409 => [ErrorCodes::CONFLICT, 'The request conflicts with the current state of the resource.'],
            429 => [ErrorCodes::RATE_LIMITED, 'Too many requests. Try again later.'],
            503 => [ErrorCodes::SERVICE_UNAVAILABLE, 'Service is temporarily unavailable.'],
            default => $status >= 500
                ? [ErrorCodes::SERVER_ERROR, 'An unexpected error occurred.']
                : [ErrorCodes::INVALID_REQUEST, 'The request could not be processed.'],
        };

        return [$code, $message, $status, [], $headers];
    }

    /**
     * Field-level validation details with contract-style codes (API_CONTRACTS.md §8).
     *
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private static function validationDetails(ValidationException $exception): array
    {
        $failed = $exception->validator->failed();
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            $rule = array_key_first($failed[$field] ?? []);
            $details[] = [
                'field' => (string) $field,
                'code' => self::validationCode($rule),
                'message' => $messages[0],
            ];
        }

        return $details;
    }

    private static function validationCode(mixed $rule): string
    {
        return match (strtolower((string) $rule)) {
            'required', 'required_if', 'required_unless', 'required_with', 'required_without', 'required_with_all', 'required_without_all' => 'REQUIRED',
            'email', 'date', 'date_format', 'uuid', 'url', 'ip', 'ipv4', 'ipv6', 'timezone', 'regex', 'json' => 'INVALID_FORMAT',
            'in', 'not_in' => 'NOT_ALLOWED',
            'integer', 'numeric', 'boolean', 'array', 'string', 'file', 'image' => 'INVALID_TYPE',
            'min', 'max', 'between', 'size', 'gt', 'gte', 'lt', 'lte' => 'OUT_OF_RANGE',
            'unique' => 'RESOURCE_EXISTS',
            'exists' => 'REFERENCE_NOT_FOUND',
            'confirmed' => 'MISMATCH',
            'distinct' => 'DUPLICATE_VALUE',
            default => 'VALIDATION_ERROR',
        };
    }
}
