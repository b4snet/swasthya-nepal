import { vi } from 'vitest';

export function jsonOk(data: unknown, meta: Record<string, unknown> = {}) {
  return new Response(JSON.stringify({ data, meta: { context: { tenantId: null, facilityId: null, branchId: null, timezone: 'UTC' }, ...meta }, links: {} }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

export function jsonError(status: number, code: string, message: string) {
  return new Response(
    JSON.stringify({ error: { code, message, correlationId: 'corr-123' } }),
    { status, headers: { 'Content-Type': 'application/json' } },
  );
}

export function stubFetch(...responses: Response[]) {
  let i = 0;
  const fn = vi.fn(async () => {
    if (i >= responses.length) throw new Error(`stubFetch: no more responses (${responses.length} provided)`);
    const resp = responses[i++];
    // Response bodies can only be read once; clone so repeated calls work.
    return resp.clone();
  });
  // Use spyOn so vi.restoreAllMocks() can properly restore the original.
  vi.spyOn(globalThis, 'fetch').mockImplementation(fn);
  return fn;
}

export function assignments() {
  return [
    {
      organizationId: 'org-1',
      organizationCode: 'SMOKE',
      facilityId: 'fac-1',
      facilityName: 'Smoke Central',
      roles: ['hospital_admin'],
    },
  ];
}
