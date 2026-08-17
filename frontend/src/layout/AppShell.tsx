import { useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';
import { AUDIT_ROLES, BILLING_ROLES, QUEUE_ROLES } from '../auth/roles';
import { useI18n } from '../i18n/I18nProvider';
import type { MessageKey } from '../i18n/locales/en';
import { Button } from '../components/ui';
import './shell.css';

// Nav gating is a UX control: every code below exists in the seeded RBAC
// catalog (frontend/src/auth/roles.ts) and the backend remains authoritative.
// Labels are message keys so the shell renders in English or Nepali (Phase 22).
const NAV: Array<{ to: string; labelKey: MessageKey; icon: string; roles: string[] }> = [
  { to: '/', labelKey: 'nav.dashboard', icon: '⌂', roles: [] },
  { to: '/patients', labelKey: 'nav.patients', icon: '◉', roles: [] },
  { to: '/appointments', labelKey: 'nav.appointments', icon: '◷', roles: [] },
  { to: '/queue', labelKey: 'nav.queue', icon: '≣', roles: [...QUEUE_ROLES] },
  { to: '/billing', labelKey: 'nav.billing', icon: '₨', roles: [...BILLING_ROLES] },
  { to: '/audit', labelKey: 'nav.audit', icon: '☰', roles: [...AUDIT_ROLES] },
];

function allowed(roles: string[], hasRole: (r: string) => boolean) {
  return roles.length === 0 || roles.some((r) => hasRole(r));
}

function ContextSwitcher() {
  const { facilities, selectedFacilityId, selectFacility } = useTenant();
  const { t } = useI18n();
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
        {t('shell.facility')}
      </label>
      <select
        id="facility-select"
        className="ctx-select"
        value={selectedFacilityId ?? ''}
        onChange={(e) => selectFacility(e.target.value)}
        data-testid="facility-select"
      >
        <option value="" disabled>
          {t('shell.chooseFacility')}
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

/** Phase 22 localization: English ⇄ नेपाली toggle, persisted and applied to <html lang>. */
function LanguageToggle() {
  const { locale, setLocale } = useI18n();
  const next: 'en' | 'ne' = locale === 'en' ? 'ne' : 'en';
  return (
    <button
      type="button"
      className="lang-toggle"
      onClick={() => setLocale(next)}
      aria-label={next === 'ne' ? 'नेपालीमा हेर्नुहोस्' : 'View in English'}
      title={next === 'ne' ? 'नेपालीमा हेर्नुहोस्' : 'View in English'}
      data-testid="lang-toggle"
    >
      {locale === 'en' ? 'नेपाली' : 'EN'}
    </button>
  );
}

export function AppShell() {
  const { user, logout } = useAuth();
  const { selectedFacilityId } = useTenant();
  const hasRole = useTenant().hasRole;
  const { t } = useI18n();
  const navigate = useNavigate();
  const location = useLocation();
  const [mobileMoreOpen, setMobileMoreOpen] = useState(false);

  const visible = NAV.filter((n) => allowed(n.roles, hasRole)).map((n) => ({ ...n, label: t(n.labelKey) }));
  const primary = visible.slice(0, 4);
  const rest = visible.slice(4);

  const onLogout = async () => {
    await logout();
    navigate('/login');
  };

  return (
    <div className="app-shell">
      <a className="skip-link" href="#content">
        {t('shell.skipToContent')}
      </a>
      <header className="app-header">
        <span className="brand" aria-hidden="true">
          ◈
        </span>
        <strong className="brand-name">{t('app.name')}</strong>
        <div className="app-header__spacer" />
        <ContextSwitcher />
        <LanguageToggle />
        <span className="user-chip" title={user?.email ?? ''}>
          {user?.email?.slice(0, 2).toUpperCase()}
        </span>
      </header>
      <div className="app-body">
        <aside className="app-sidebar" aria-label={t('shell.primary')}>
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
              {t('shell.signOut')}
            </Button>
          </div>
        </aside>
        <main className="app-content" id="content" tabIndex={-1}>
          <Outlet />
        </main>
      </div>

      {/* Mobile bottom navigation — DESIGN_SYSTEM.md §5 */}
      <nav className="bottom-nav" aria-label={t('shell.primary')}>
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
            {t('shell.more')}
          </button>
        )}
      </nav>
      {mobileMoreOpen && (
        <div className="more-sheet" role="menu" aria-label={t('shell.moreDestinations')}>
          {rest.map((item) => (
            <NavLink key={item.to} to={item.to} className="more-sheet__item" onClick={() => setMobileMoreOpen(false)}>
              <span aria-hidden="true">{item.icon}</span> {item.label}
            </NavLink>
          ))}
        </div>
      )}

      {location.pathname !== '/login' && !selectedFacilityId && (
        <div className="ctx-required" role="alert">
          {t('shell.selectFacilityRequired')}
        </div>
      )}
    </div>
  );
}

// Re-export Select-free helper used by pages for facility-scoped requests.
export function useFacility() {
  return useTenant().selectedFacilityId;
}
