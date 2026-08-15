/**
 * Response envelope for Edge Functions (Edge mirror of
 * backend App\Support\Envelope — API_CONTRACTS.md §7–8).
 *
 * Success: { data, meta: { context: { tenantId, facilityId, branchId,
 *                                   timezone } }, links }
 * Error:   { error: { code, message, details?, correlationId } }
 *
 * Every response carries X-Request-Id and X-Correlation-Id. The context echo
 * is the server-derived fact — never client input — so client state can
 * never drift from server truth.
 */
import type { ResolvedContext } from './types.ts';

export const HEADER_REQUEST_ID = 'X-Request-Id';
export const HEADER_CORRELATION_ID = 'X-Correlation-Id';

export interface ContextEcho {
  tenantId: string | null;
  facilityId: string | null;
  branchId: string | null;
  timezone: string;
}

export function contextEcho(context: ResolvedContext | null): ContextEcho {
  return {
    tenantId: context?.organizationId ?? null,
    facilityId: context?.facilityId ?? null,
    branchId: context?.branchId ?? null,
    timezone: 'UTC',
  };
}

export function success(
  data: unknown,
  correlationId: string,
  context: ResolvedContext | null = null,
  meta: Record<string, unknown> = {},
  status = 200,
  extraHeaders: Record<string, string> = {},
): Response {
  const headers = new Headers({ 'Content-Type': 'application/json' });
  headers.set(HEADER_REQUEST_ID, correlationId);
  headers.set(HEADER_CORRELATION_ID, correlationId);
  for (const [name, value] of Object.entries(extraHeaders)) headers.set(name, value);

  return new Response(JSON.stringify({
    data,
    meta: { context: contextEcho(context), ...meta },
    links: {},
  }), { status, headers });
}

export function error(
  code: string,
  message: string,
  status: number,
  correlationId: string,
  details: unknown = {},
  extraHeaders: Record<string, string> = {},
): Response {
  const headers = new Headers({ 'Content-Type': 'application/json' });
  headers.set(HEADER_REQUEST_ID, correlationId);
  headers.set(HEADER_CORRELATION_ID, correlationId);
  for (const [name, value] of Object.entries(extraHeaders)) headers.set(name, value);

  // `details` is rendered verbatim at error.details — the Laravel contract
  // for validation errors is an ARRAY of { field, code, message } (mirror of
  // ApiExceptionMapper::validationDetails).
  const hasDetails = details !== undefined && details !== null
    && (typeof details !== 'object' || Object.keys(details as object).length > 0);
  const payload: { error: { code: string; message: string; correlationId: string; details?: unknown } } = {
    error: { code, message, correlationId },
  };
  if (hasDetails) payload.error = { ...payload.error, details };

  return new Response(JSON.stringify(payload), { status, headers });
}

/** Read or mint the correlation id for a request. */
export function correlationId(req: Request): string {
  const incoming = req.headers.get(HEADER_CORRELATION_ID);
  return incoming !== null && incoming !== '' ? incoming : crypto.randomUUID();
}
