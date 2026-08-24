import { Navigate } from 'react-router-dom';
import { useTenant } from '../context/TenantContext';

/**
 * Role-based dashboard router.
 *
 * MODEL A — Unified post-login entry: ALL authenticated users begin at /dashboard.
 *
 * The global Dashboard renders role-aware content based on the user's:
 *   - role
 *   - organization
 *   - facility
 *   - permissions
 *   - module entitlements
 *
 * From /dashboard, users access their primary workspace via quick-actions
 * or the module rail.
 *
 * Security note: this is UI-routing convenience only. Backend authorization
 * and RLS remain authoritative.
 */
export function RoleDashboardRouter() {
  const { ready } = useTenant();

  // Not ready — still resolving context. Show nothing (Gate handles the spinner).
  if (!ready) return null;

  // MODEL A: All roles enter at /dashboard
  return <Navigate to="/dashboard" replace />;
}
