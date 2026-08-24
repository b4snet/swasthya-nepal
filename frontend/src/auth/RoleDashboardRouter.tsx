import { Navigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';
import { MODULES } from '../navigation/modules';

/**
 * Role-based dashboard router.
 *
 * Replaces the blanket `/ → /dashboard` redirect with a role-aware landing:
 *
 *   superadmin (no facility)  → /dashboard   (platform overview)
 *   org_admin (no facility)   → /dashboard   (org overview)
 *   hospital_admin            → /hospital    (facility operations)
 *   doctor / nurse            → /clinical    (patient care)
 *   pharmacist                → /pharmacy    (dispensing)
 *   lab_technician / supervisor → /laboratory (orders/results)
 *   radiographer / radiologist  → /radiology  (imaging)
 *   billing_clerk             → /finance     (billing)
 *   receptionist              → /hospital    (front desk)
 *   org_finance               → /dashboard   (org-level finance view)
 *   fallback                  → /dashboard
 *
 * Security note: this is UI-routing convenience only. Backend authorization
 * and RLS remain authoritative — hiding a module from the landing page
 * never grants or denies access.
 */

/** Ordered mapping: first matching role wins. */
const ROLE_DESTINATIONS: Array<{ roles: string[]; path: string }> = [
  // ── Platform / org-wide: always the global dashboard ──
  { roles: ['superadmin'], path: '/dashboard' },
  { roles: ['org_admin'], path: '/dashboard' },
  { roles: ['org_finance'], path: '/dashboard' },
  { roles: ['support_agent'], path: '/dashboard' },

  // ── Facility operations ──
  { roles: ['hospital_admin', 'branch_manager'], path: '/hospital' },

  // ── Clinical care ──
  { roles: ['doctor'], path: '/clinical' },
  { roles: ['nurse'], path: '/clinical' },
  { roles: ['receptionist'], path: '/hospital' },

  // ── Specialized departments ──
  { roles: ['pharmacist'], path: '/pharmacy' },
  { roles: ['lab_technician', 'lab_supervisor'], path: '/laboratory' },
  { roles: ['radiographer', 'radiologist'], path: '/radiology' },

  // ── Financial operations ──
  { roles: ['billing_clerk'], path: '/finance' },
];

/** Modules that require a facility to be meaningful. */
const FACILITY_REQUIRED_PREFIXES = ['/hospital', '/clinical', '/pharmacy', '/laboratory', '/radiology', '/finance', '/procurement', '/blood-bank', '/nursing'];

function needsFacility(path: string): boolean {
  return FACILITY_REQUIRED_PREFIXES.some((p) => path === p || path.startsWith(p + '/'));
}

/** Check if the user's visible modules include the target path prefix. */
function canAccessModule(path: string, visibleModules: string[]): boolean {
  // Platform-only routes don't require module visibility.
  if (path === '/dashboard') return true;
  // Match by module route prefix.
  return visibleModules.some((prefix) => path === prefix || path.startsWith(prefix + '/'));
}

export function RoleDashboardRouter() {
  const { roles, facilities, hasRole, ready } = useTenant();

  // Not ready — still resolving context. Show nothing (Gate handles the spinner).
  if (!ready) return null;

  const hasFacility = facilities.length > 0;

  // Derive the visible module route prefixes for this user.
  const visibleModules = MODULES
    .filter((m) => m.roles.length === 0 || m.roles.some((r) => hasRole(r)))
    .map((m) => m.routePrefix);

  // Find the first role match in the priority order.
  let destination = '/dashboard'; // ultimate fallback
  for (const entry of ROLE_DESTINATIONS) {
    if (entry.roles.some((r) => roles.includes(r))) {
      // Platform-only users (no facility) can't land on facility-scoped modules.
      if (!hasFacility && needsFacility(entry.path)) {
        destination = '/dashboard';
      } else if (canAccessModule(entry.path, visibleModules)) {
        destination = entry.path;
      }
      break;
    }
  }

  return <Navigate to={destination} replace />;
}
