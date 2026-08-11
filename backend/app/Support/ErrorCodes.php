<?php

namespace App\Support;

/**
 * Stable machine-readable error codes (API_CONTRACTS.md §8, MASTER_RULES.md §17.1).
 *
 * Codes are part of the versioned API contract: they are additive only within
 * a version, and never renamed or renumbered without a new API version.
 */
final class ErrorCodes
{
    public const INVALID_REQUEST = 'INVALID_REQUEST';

    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const INVALID_TOKEN = 'INVALID_TOKEN';

    public const TOKEN_EXPIRED = 'TOKEN_EXPIRED';

    public const TOKEN_REVOKED = 'TOKEN_REVOKED';

    public const MFA_REQUIRED = 'MFA_REQUIRED';

    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    public const FORBIDDEN = 'FORBIDDEN';

    public const SCOPE_DENIED = 'SCOPE_DENIED';

    public const FACILITY_DENIED = 'FACILITY_DENIED';

    public const BRANCH_DENIED = 'BRANCH_DENIED';

    public const TENANT_SUSPENDED = 'TENANT_SUSPENDED';

    public const NOT_FOUND = 'NOT_FOUND';

    public const CONFLICT = 'CONFLICT';

    public const LOCK_CONFLICT = 'LOCK_CONFLICT';

    public const IDEMPOTENCY_REUSE = 'IDEMPOTENCY_REUSE';

    public const RESOURCE_EXISTS = 'RESOURCE_EXISTS';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';

    private function __construct()
    {
        // Constants only.
    }
}
