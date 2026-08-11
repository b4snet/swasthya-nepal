import { useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';
import { Button } from '../components/ui';
import './shell.css';

const NAV = [
  { to: '/', label: 'Dashboard', icon: '⌂', roles: [] },
  { to: '/patients', label: 'Patients', icon: '◉', roles: [] },
  { to: '/appointments', label: 'Appointments', icon: '◷', roles: [] },
  { to: '/queue', label: 'Queue', icon: '≣', roles: ['hospital_admin', 'doctor', 'nurse', 'receptionist'] },
  { to: '/billing', label: 'Billing', icon: '₨', roles: ['hospital_admin', 'accountant', 'billing_clerk'] },
  { to: '/audit', label: 'Audit', icon: '☰', roles: ['hospital_admin', 'org_admin', 'platform_admin'] },
];

function allowed(roles: string[], hasRole: (r: string) => boolean) {
  return roles.length === 0 || roles.some((r) => hasRole(r));
}

function ContextSwitcher() {
  const { facilities, selectedFacilityId, selectFacility } = useTenant();
  if (facilities.length <= 1) {
    return (
      <span className="ctx-badge" data-testid="context-badge">
        {facilities[0]?.name ?? 'Platform'}
      </span>
    );
  }
  return (
    <div className="ctx-switch">
      <label className="visually-hidden" htmlFor="facility-select">
        Facility
      </label>
      <select
        id="facility-select"
        className="ctx-select"
        value={selectedFacilityId ?? ''}
        onChange={(e) => selectFacility(e.target.value)}
        data-testid="facility-select"
      >
        <option value="" disabled>
          Choose facility
        </option>
        {facilities.map((f) => (
          <option key={f.id} value={f.id}>
            {f.name}
          </option>
        ))}
      </select>
    </div>
  );
}

export function AppShell() {
  const { user, logout } = useAuth();
  const { selectedFacilityId } = useTenant();
  const hasRole = useTenant().hasRole;
  const navigate = useNavigate();
  const location = useLocation();
  const [mobileMoreOpen, setMobileMoreOpen] = useState(false);

  const visible = NAV.filter((n) => allowed(n.roles, hasRole));
  const primary = visible.slice(0, 4);
  const rest = visible.slice(4);

  const onLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="app-shell">
      <a className="skip-link" href="#content">
        Skip to content
      </a>
      <header className="app-header">
        <span className="brand" aria-hidden="true">
          ◈
        </span>
        <strong className="brand-name">Swasthya</strong>
        <div className="app-header__spacer" />
        <ContextSwitcher />
        <span className="user-chip" title={user?.email ?? ''}>
          {user?.email?.slice(0, 2).toUpperCase()}
        </span>
      </header>
      <div className="app-body">
        <aside className="app-sidebar" aria-label="Primary">
          <nav className="side-nav">
            {visible.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                className={({ isActive }) => `side-nav__item ${isActive ? 'side-nav__item--active' : ''}`}
              >
                <span aria-hidden="true">{item.icon}</span>
                <span className="side-nav__label">{item.label}</span>
              </NavLink>
            ))}
          </nav>
          <div className="side-nav__footer">
            <Button variant="ghost" onClick={onLogout} className="side-nav__logout">
              Sign out
            </Button>
          </div>
        </aside>
        <main className="app-content" id="content" tabIndex={-1}>
          <Outlet />
        </main>
      </div>

      {/* Mobile bottom navigation — DESIGN_SYSTEM.md §5 */}
      <nav className="bottom-nav" aria-label="Primary">
        {primary.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            className={({ isActive }) => `bottom-nav__item ${isActive ? 'active' : ''}`}
          >
            <span aria-hidden="true" className="bottom-nav__icon">
              {item.icon}
            </span>
            {item.label}
          </NavLink>
        ))}
        {rest.length > 0 && (
          <button className="bottom-nav__item" aria-expanded={mobileMoreOpen} onClick={() => setMobileMoreOpen((v) => !v)}>
            <span aria-hidden="true" className="bottom-nav__icon">
              ⋯
            </span>
            More
          </button>
        )}
      </nav>
      {mobileMoreOpen && (
        <div className="more-sheet" role="menu" aria-label="More destinations">
          {rest.map((item) => (
            <NavLink key={item.to} to={item.to} className="more-sheet__item" onClick={() => setMobileMoreOpen(false)}>
              <span aria-hidden="true">{item.icon}</span> {item.label}
            </NavLink>
          ))}
        </div>
      )}

      {location.pathname !== '/login' && !selectedFacilityId && (
        <div className="ctx-required" role="alert">
          Select a facility to continue.
        </div>
      )}
    </div>
  );
}

// Re-export Select-free helper used by pages for facility-scoped requests.
export function useFacility() {
  return useTenant().selectedFacilityId;
}
