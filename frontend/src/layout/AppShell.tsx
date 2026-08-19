import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';
import { useNetworkStatus } from '../hooks/useNetworkStatus';
import { ADMIN_ROLES, AUDIT_ROLES, BILLING_ROLES, QUEUE_ROLES } from '../auth/roles';
import { useI18n } from '../i18n/I18nProvider';
import type { MessageKey } from '../i18n/locales/en';
import { Button, Dialog } from '../components/ui';
import './shell.css';

// Nav gating is a UX control: every code below exists in the seeded RBAC
// catalog (frontend/src/auth/roles.ts) and the backend remains authoritative.
// Labels are message keys so the shell renders in English or Nepali (Phase 22).
const NAV: Array<{ to: string; labelKey: MessageKey; icon: string; roles: string[] }> = [
  { to: '/', labelKey: 'nav.dashboard', icon: '⌂', roles: [] },
  { to: '/patients', labelKey: 'nav.patients', icon: '◉', roles: [] },
  { to: '/appointments', labelKey: 'nav.appointments', icon: '◷', roles: [] },
  { to: '/queue', labelKey: 'nav.queue', icon: '≣', roles: [...QUEUE_ROLES] },
  { to: '/pharmacy', labelKey: 'nav.pharmacy', icon: '💊', roles: [] },
  { to: '/inventory', labelKey: 'nav.inventory', icon: '📦', roles: [] },
  { to: '/procurement', labelKey: 'nav.procurement', icon: '🛒', roles: [] },
  { to: '/billing', labelKey: 'nav.billing', icon: '₨', roles: [...BILLING_ROLES] },
  { to: '/finance', labelKey: 'nav.finance', icon: '💰', roles: [] },
  { to: '/analytics', labelKey: 'nav.analytics', icon: '📊', roles: [] },
  { to: '/notifications', labelKey: 'nav.notifications', icon: '🔔', roles: [] },
  { to: '/radiology', labelKey: 'nav.radiology', icon: '🩻', roles: [] },
  { to: '/oncology', labelKey: 'nav.oncology', icon: '🎯', roles: [] },
  { to: '/audit', labelKey: 'nav.audit', icon: '☰', roles: [...AUDIT_ROLES] },
  { to: '/admin', labelKey: 'nav.admin', icon: '⚙', roles: [...ADMIN_ROLES] },
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

/**
 * User account menu — shows initials chip, opens dropdown with user info
 * and logout. The logout action requires confirmation.
 */
function UserMenu() {
  const { user, logout } = useAuth();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [confirmLogout, setConfirmLogout] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  // Close on outside click.
  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  // Close on Escape.
  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [open]);

  const onLogout = async () => {
    setConfirmLogout(false);
    setOpen(false);
    await logout();
    navigate('/login', { replace: true });
  };

  return (
    <div className="user-menu" ref={menuRef}>
      <button
        type="button"
        className="user-chip user-chip--clickable"
        title={user?.email ?? ''}
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        data-testid="user-menu-trigger"
      >
        {user?.email?.slice(0, 2).toUpperCase()}
      </button>

      {open && (
        <div className="user-menu__dropdown" role="menu" data-testid="user-menu-dropdown">
          <div className="user-menu__info">
            <span className="user-menu__email">{user?.email}</span>
            <span className="user-menu__id mono muted small">{user?.id?.slice(0, 8)}</span>
          </div>
          <div className="user-menu__divider" />
          <button
            type="button"
            className="user-menu__item user-menu__item--danger"
            onClick={() => { setConfirmLogout(true); setOpen(false); }}
            role="menuitem"
            data-testid="user-menu-logout"
          >
            {t('shell.signOut')}
          </button>
        </div>
      )}

      <Dialog
        open={confirmLogout}
        onClose={() => setConfirmLogout(false)}
        title={t('shell.confirmLogout')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmLogout(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" onClick={() => void onLogout()}>
              {t('shell.signOut')}
            </Button>
          </>
        }
      >
        <p>{t('shell.confirmLogoutMessage')}</p>
      </Dialog>
    </div>
  );
}

export function AppShell() {
  const { selectedFacilityId } = useTenant();
  const hasRole = useTenant().hasRole;
  const { t } = useI18n();
  const [mobileMoreOpen, setMobileMoreOpen] = useState(false);

  const visible = NAV.filter((n) => allowed(n.roles, hasRole)).map((n) => ({ ...n, label: t(n.labelKey) }));
  const primary = visible.slice(0, 4);
  const rest = visible.slice(4);

  const network = useNetworkStatus();

  return (
    <div className="app-shell">
      <a className="skip-link" href="#content">
        {t('shell.skipToContent')}
      </a>
      {!network.online && (
        <div className="offline-banner" role="alert" style={{ background: '#fee2e2', color: '#991b1b', padding: '0.5rem 1rem', textAlign: 'center', fontSize: '0.875rem' }}>
          You are offline. Some features may be unavailable.
        </div>
      )}
      <header className="app-header">
        <span className="brand" aria-hidden="true">
          ◈
        </span>
        <strong className="brand-name">{t('app.name')}</strong>
        <div className="app-header__spacer" />
        <ContextSwitcher />
        <LanguageToggle />
        <UserMenu />
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
            <UserMenuSidebar />
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

      {!selectedFacilityId && (
        <div className="ctx-required" role="status" data-testid="facility-required-banner">
          {t('shell.selectFacilityRequired')}
        </div>
      )}
    </div>
  );
}

/** Sidebar sign-out button with confirmation dialog. */
function UserMenuSidebar() {
  const { logout } = useAuth();
  const { t } = useI18n();
  const nav = useNavigate();
  const [confirmLogout, setConfirmLogout] = useState(false);

  const onLogout = async () => {
    setConfirmLogout(false);
    await logout();
    nav('/login', { replace: true });
  };

  return (
    <>
      <Button variant="ghost" onClick={() => setConfirmLogout(true)} className="side-nav__logout">
        {t('shell.signOut')}
      </Button>
      <Dialog
        open={confirmLogout}
        onClose={() => setConfirmLogout(false)}
        title={t('shell.confirmLogout')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmLogout(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" onClick={() => void onLogout()}>
              {t('shell.signOut')}
            </Button>
          </>
        }
      >
        <p>{t('shell.confirmLogoutMessage')}</p>
      </Dialog>
    </>
  );
}

// Re-export Select-free helper used by pages for facility-scoped requests.
export function useFacility() {
  return useTenant().selectedFacilityId;
}
