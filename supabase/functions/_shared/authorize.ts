/**
 * Authorization for Edge Functions (Edge mirror of
 * backend App\Support\TenantContext::can()).
 *
 * A permission is granted when an ACTIVE assignment inside the current tenant
 * covers the current facility scope and its role carries the permission.
 * Org-scoped roles cover every facility of the tenant; facility-scoped roles
 * cover exactly one. Platform context grants ONLY platform/both-scoped
 * permissions — tenant business permissions are unreachable without an
 * active support session.
 *
 * This is the APPLICATION authorization layer. RLS remains the final
 * database boundary; authorization here is defense-in-depth, exactly as in
 * the Laravel architecture (SECURITY.md §14).
 */
import type { ResolvedContext } from './types.ts';

export function can(context: ResolvedContext, permission: string): boolean {
  if (context.isPlatform) {
    for (const assignment of context.assignments) {
      if (assignment.role?.scopeType !== 'platform') continue;
      for (const candidate of assignment.role.permissions) {
        if (candidate.code === permission && candidate.scope !== 'tenant') {
          return true;
        }
      }
    }
    return false;
  }

  if (context.organizationId === null) return false;

  for (const assignment of context.assignments) {
    if (assignment.tenantId !== context.organizationId) continue;

    if (assignment.facilityId !== null) {
      if (context.facilityId === null || assignment.facilityId !== context.facilityId) {
        continue;
      }
    }

    if (assignment.role?.permissions.some((p) => p.code === permission)) {
      return true;
    }
  }

  return false;
}
