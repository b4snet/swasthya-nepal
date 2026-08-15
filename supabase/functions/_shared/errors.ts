/**
 * Shared error contract for Swasthya Edge Functions.
 *
 * Mirrors `backend/app/Exceptions/ApiException` + `ErrorCodes` (API_CONTRACTS.md
 * §8): every failure is a stable machine-readable code, a human-safe message,
 * and an HTTP status. Codes are additive only.
 */
export const ErrorCodes = {
  INVALID_REQUEST: 'INVALID_REQUEST',
  VALIDATION_ERROR: 'VALIDATION_ERROR',
  INVALID_TOKEN: 'INVALID_TOKEN',
  TOKEN_EXPIRED: 'TOKEN_EXPIRED',
  TOKEN_REVOKED: 'TOKEN_REVOKED',
  MFA_REQUIRED: 'MFA_REQUIRED',
  INVALID_CREDENTIALS: 'INVALID_CREDENTIALS',
  FORBIDDEN: 'FORBIDDEN',
  SCOPE_DENIED: 'SCOPE_DENIED',
  FACILITY_DENIED: 'FACILITY_DENIED',
  BRANCH_DENIED: 'BRANCH_DENIED',
  TENANT_SUSPENDED: 'TENANT_SUSPENDED',
  NOT_FOUND: 'NOT_FOUND',
  CONFLICT: 'CONFLICT',
  LOCK_CONFLICT: 'LOCK_CONFLICT',
  IDEMPOTENCY_REUSE: 'IDEMPOTENCY_REUSE',
  RESOURCE_EXISTS: 'RESOURCE_EXISTS',
  RATE_LIMITED: 'RATE_LIMITED',
  SERVER_ERROR: 'SERVER_ERROR',
  SERVICE_UNAVAILABLE: 'SERVICE_UNAVAILABLE',
} as const;

export type ErrorCode = (typeof ErrorCodes)[keyof typeof ErrorCodes];

/**
 * An expected, controlled failure. The message is user-safe: it must never
 * contain secrets, tokens, or stack traces (MASTER_RULES.md §10).
 *
 * NOTE: declared WITHOUT constructor parameter properties so the module runs
 * under Node's type-stripping (local harness) and Deno alike.
 */
export class EdgeError extends Error {
  readonly code: ErrorCode;

  readonly status: number;

  constructor(code: ErrorCode, message: string, status = 400) {
    super(message);
    this.name = 'EdgeError';
    this.code = code;
    this.status = status;
  }
}

/** An expected failure specific to JWT verification (mirror of JwtClaims). */
export class JwtError extends EdgeError {
  constructor(code: ErrorCode, message: string, status = 401) {
    super(code, message, status);
    this.name = 'JwtError';
  }
}
