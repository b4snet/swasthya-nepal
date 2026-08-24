import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { CommandPalette } from '../components/CommandPalette';
import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';
import { useNetworkStatus } from '../hooks/useNetworkStatus';
import { useI18n } from '../i18n/I18nProvider';
import { Button, Dialog } from '../components/ui';
import { useAccess } from '../auth/useAccess';
import {
  LogOut,
  Globe,
  Building2,
  ChevronRight,
  MoreHorizontal,
  Bell,
  Search,
  LayoutDashboard,
} from 'lucide-react';
import {
  MODULES,
  getActiveModule,
  filterModulesByRole,
  type NavModule,
} from '../navigation/modules';
import './shell.css';

/** Time-of-day greeting */
function getGreeting(): string {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  return 'Good evening';
}

// ── Facility switcher ──
function FacilitySwitcher() {
  const { facilities, selectedFacilityId, selectFacility } = useTenant();
  const { t } = useI18n();
  if (facilities.length <= 1) {
    return (
      <span className="facility-badge" data-testid="context-badge">
        <Building2 size={14} />
        {facilities[0]?.name ?? 'Platform'}
      </span>
    );
  }
  return (
    <div className="facility-switch">
      <label className="visually-hidden" htmlFor="facility-select">
        {t('shell.facility')}
      </label>
      <select
        id="facility-select"
        className="facility-select"
        value={selectedFacilityId ?? ''}
        onChange={(e) => selectFacility(e.target.value)}
        data-testid="facility-select"
      >
        <option value="" disabled>{t('shell.chooseFacility')}</option>
        {facilities.map((f) => (
          <option key={f.id} value={f.id}>{f.name}</option>
        ))}
      </select>
    </div>
  );
}

// ── Language toggle ──
function LanguageToggle() {
  const { locale, setLocale } = useI18n();
  const next: 'en' | 'ne' = locale === 'en' ? 'ne' : 'en';
  return (
    <button
      type="button"
      className="lang-btn"
      onClick={() => setLocale(next)}
      aria-label={next === 'ne' ? 'नेपालीमा हेर्नुहोस्' : 'View in English'}
      title={next === 'ne' ? 'नेपालीमा हेर्नुहोस्' : 'View in English'}
      data-testid="lang-toggle"
    >
      <Globe size={15} />
      {locale === 'en' ? 'नेपाली' : 'EN'}
    </button>
  );
}

// ── User menu ──
function UserMenu() {
  const { user, logout } = useAuth();
  const access = useAccess();
  const { t } = useI18n();
  const navigate = useNavigate();
  const [open, setOpen] = useState(false);
  const [confirmLogout, setConfirmLogout] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  useEffect(() => {
    if (!open) return;
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false); };
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
        className="user-avatar"
        title={access.getDisplayName()}
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        data-testid="user-menu-trigger"
      >
        {access.getInitials()}
      </button>

      {open && (
        <div className="user-dropdown" role="menu" data-testid="user-menu-dropdown">
          <div className="user-dropdown__header">
            <span className="user-dropdown__email">{user?.email}</span>
            <span className="user-dropdown__id">{user?.id?.slice(0, 8)}</span>
          </div>
          <div className="user-dropdown__sep" />
          <button
            type="button"
            className="user-dropdown__item user-dropdown__item--danger"
            onClick={() => { setConfirmLogout(true); setOpen(false); }}
            role="menuitem"
            data-testid="user-menu-logout"
          >
            <LogOut size={15} />
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

// ── Sidebar user/logout ──
function SidebarUser() {
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
      <button type="button" className="sidebar-logout" onClick={() => setConfirmLogout(true)}>
        <LogOut size={16} />
        <span>{t('shell.signOut')}</span>
      </button>
      <Dialog
        open={confirmLogout}
        onClose={() => setConfirmLogout(false)}
        title={t('shell.confirmLogout')}
        footer={
          <>
            <Button variant="ghost" onClick={() => setConfirmLogout(false)}>{t('common.cancel')}</Button>
            <Button variant="danger" onClick={() => void onLogout()}>{t('shell.signOut')}</Button>
          </>
        }
      >
        <p>{t('shell.confirmLogoutMessage')}</p>
      </Dialog>
    </>
  );
}

// ── Module icon rail (leftmost narrow column) ──
function ModuleRail({
  modules,
  dashboardModule,
  activeModule,
  onSelect,
}: {
  modules: NavModule[];
  dashboardModule: NavModule | undefined;
  activeModule: NavModule | undefined;
  onSelect: (m: NavModule) => void;
}) {
  const { t } = useI18n();
  const location = useLocation();
  const isDashboardActive = dashboardModule && (
    location.pathname === dashboardModule.routePrefix ||
    location.pathname === dashboardModule.defaultTo
  );
  return (
    <nav className="module-rail" role="navigation" aria-label="Modules">
      {/* Global Dashboard — always first, always visible */}
      {dashboardModule && (
        <button
          type="button"
          className={`module-rail__item module-rail__item--dashboard ${isDashboardActive ? 'module-rail__item--active' : ''}`}
          onClick={() => onSelect(dashboardModule)}
          title={t(dashboardModule.labelKey)}
          aria-label={t(dashboardModule.labelKey)}
          aria-current={isDashboardActive ? 'page' : undefined}
          data-testid="module-dashboard"
        >
          <dashboardModule.Icon size={20} strokeWidth={1.75} />
        </button>
      )}
      {/* Separator between Dashboard and modules */}
      {dashboardModule && <div className="module-rail__separator" />}
      {/* Feature modules */}
      {modules.map((m) => (
        <button
          key={m.key}
          type="button"
          className={`module-rail__item ${activeModule?.key === m.key ? 'module-rail__item--active' : ''}`}
          onClick={() => onSelect(m)}
          title={t(m.labelKey)}
          aria-label={t(m.labelKey)}
          data-testid={`module-${m.key}`}
        >
          <m.Icon size={20} strokeWidth={1.75} />
        </button>
      ))}
    </nav>
  );
}

// ── Contextual sub-navigation panel ──
function SubNav({
  module: mod,
  pathname,
}: {
  module: NavModule;
  pathname: string;
}) {
  const { t } = useI18n();
  return (
    <div className="subnav" role="navigation" aria-label={t(mod.labelKey)}>
      <div className="subnav__header">
        <mod.Icon size={16} strokeWidth={1.75} />
        <span className="subnav__title">{t(mod.labelKey)}</span>
      </div>
      <div className="subnav__items">
        {mod.children.map((child) => {
          const isActive =
            pathname === child.to ||
            (child.to !== mod.defaultTo && pathname.startsWith(child.to + '/'));
          return (
            <NavLink
              key={child.key}
              to={child.to}
              className={`subnav__link ${isActive ? 'subnav__link--active' : ''}`}
              data-testid={`subnav-${child.key}`}
            >
              <child.Icon size={15} strokeWidth={1.75} />
              <span>{t(child.labelKey)}</span>
            </NavLink>
          );
        })}
      </div>
    </div>
  );
}

// ── Breadcrumbs ──
function Breadcrumbs({
  activeModule,
  pathname,
}: {
  activeModule: NavModule | undefined;
  pathname: string;
}) {
  const { t } = useI18n();

  // Global dashboard — show just "Dashboard"
  if (pathname === '/dashboard') {
    return (
      <nav className="breadcrumbs" aria-label="Breadcrumb">
        <span className="breadcrumbs__item breadcrumbs__item--current">{t('nav.dashboard')}</span>
      </nav>
    );
  }

  if (!activeModule) return null;

  // Find the active child
  const activeChild = activeModule.children.find(
    (c) => pathname === c.to || (c.to !== activeModule.defaultTo && pathname.startsWith(c.to + '/')),
  );

  return (
    <nav className="breadcrumbs" aria-label="Breadcrumb">
      <span className="breadcrumbs__item breadcrumbs__item--muted">Swasthya</span>
      <ChevronRight size={12} className="breadcrumbs__sep" />
      <span className="breadcrumbs__item">{t(activeModule.labelKey)}</span>
      {activeChild && activeChild.to !== activeModule.defaultTo && (
        <>
          <ChevronRight size={12} className="breadcrumbs__sep" />
          <span className="breadcrumbs__item breadcrumbs__item--current">{t(activeChild.labelKey)}</span>
        </>
      )}
    </nav>
  );
}

// ── Main shell ──
export function AppShell() {
  const tenant = useTenant();
  const hasRole = tenant.hasRole;
  const { t } = useI18n();
  const location = useLocation();
  const navigate = useNavigate();
  const network = useNetworkStatus();
  const access = useAccess();

  const allModules = filterModulesByRole(MODULES, hasRole);
  // Split out the persistent Dashboard item (always first) from other modules
  const dashboardModule = allModules.find((m) => m.persistent);
  const visibleModules = allModules.filter((m) => !m.persistent);
  const activeModule = getActiveModule(location.pathname);

  // Mobile sub-nav state
  const [mobileSubNavOpen, setMobileSubNavOpen] = useState(false);

  // When a module is selected from the rail, navigate to its default
  const handleModuleSelect = (m: NavModule) => {
    navigate(m.defaultTo);
    setMobileSubNavOpen(false);
  };

  // Mobile: bottom nav shows the 4 most important modules
  const mobileModules = visibleModules.slice(0, 4);
  const moreModules = visibleModules.slice(4);

  return (
    <div className="app-shell app-shell--module-first">
      <CommandPalette />
      <a className="skip-link" href="#content">{t('shell.skipToContent')}</a>

      {!network.online && (
        <div className="offline-bar" role="alert">
          You are offline. Some features may be unavailable.
        </div>
      )}

      {/* ── Top header ── */}
      <header className="app-header">
        <div className="app-header__left">
          <span className="app-logo" aria-hidden="true">
            <svg width="28" height="28" viewBox="0 0 28 28" fill="none">
              <rect width="28" height="28" rx="6" fill="#1570ef"/>
              <path d="M8 14h12M14 8v12" stroke="#fff" strokeWidth="2.2" strokeLinecap="round"/>
            </svg>
          </span>
          <strong className="app-title">Swasthya</strong>
          <Breadcrumbs activeModule={activeModule} pathname={location.pathname} />
        </div>
        <div className="app-header__right">
          <span className="app-greeting">
            {getGreeting()}, {access.getDisplayName()}
          </span>
          <button
            type="button"
            className="lang-btn"
            onClick={() => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }))}
            title="Search (Ctrl+K)"
            aria-label="Search"
          >
            <Search size={15} />
          </button>
          <button
            type="button"
            className="lang-btn"
            onClick={() => navigate('/communications/notifications')}
            title="Notifications"
            aria-label="Notifications"
          >
            <Bell size={15} />
          </button>
          <FacilitySwitcher />
          <LanguageToggle />
          <UserMenu />
        </div>
      </header>

      <div className="app-body">
        {/* ── Module rail (icon sidebar) ── */}
        <ModuleRail
          modules={visibleModules}
          dashboardModule={dashboardModule}
          activeModule={activeModule}
          onSelect={handleModuleSelect}
        />

        {/* ── Contextual sub-navigation ── */}
        {activeModule && (
          <SubNav module={activeModule} pathname={location.pathname} />
        )}

        {/* ── Content ── */}
        <main className="app-content" id="content" tabIndex={-1}>
          <div key={location.pathname} className="page-transition">
            <Outlet />
          </div>
        </main>
      </div>

      {/* ── Mobile bottom nav ── */}
      <nav className="bottom-nav" aria-label={t('shell.primary')}>
        {/* Dashboard always first on mobile */}
        {dashboardModule && (
          <button
            type="button"
            className={`bottom-nav__item ${location.pathname === '/dashboard' ? 'bottom-nav__item--active' : ''}`}
            onClick={() => navigate('/dashboard')}
            aria-label={t(dashboardModule.labelKey)}
          >
            <LayoutDashboard size={20} strokeWidth={1.75} />
            <span>{t(dashboardModule.labelKey)}</span>
          </button>
        )}
        {mobileModules.map((m) => (
          <button
            key={m.key}
            type="button"
            className={`bottom-nav__item ${activeModule?.key === m.key ? 'bottom-nav__item--active' : ''}`}
            onClick={() => handleModuleSelect(m)}
          >
            <m.Icon size={20} strokeWidth={1.75} />
            <span>{t(m.labelKey)}</span>
          </button>
        ))}
        {moreModules.length > 0 && (
          <button
            type="button"
            className="bottom-nav__item"
            onClick={() => setMobileSubNavOpen((v) => !v)}
          >
            <MoreHorizontal size={20} strokeWidth={1.75} />
            <span>{t('shell.more')}</span>
          </button>
        )}
      </nav>

      {/* ── Mobile module drawer ── */}
      {mobileSubNavOpen && (
        <div className="mobile-sheet" role="menu" aria-label="More modules">
          {moreModules.map((m) => (
            <button
              key={m.key}
              type="button"
              className="mobile-sheet__item"
              onClick={() => handleModuleSelect(m)}
            >
              <m.Icon size={18} strokeWidth={1.75} />
              <span>{t(m.labelKey)}</span>
              <ChevronRight size={14} className="mobile-sheet__chevron" />
            </button>
          ))}
        </div>
      )}

      {/* ── Mobile sub-navigation drawer (when a module is active) ── */}
      {activeModule && mobileSubNavOpen && (
        <div className="mobile-sheet mobile-sheet--subnav" role="menu" aria-label={t(activeModule.labelKey)}>
          <div className="mobile-sheet__header">
            <activeModule.Icon size={16} strokeWidth={1.75} />
            <span>{t(activeModule.labelKey)}</span>
          </div>
          {activeModule.children.map((child) => (
            <NavLink
              key={child.key}
              to={child.to}
              className="mobile-sheet__item"
              onClick={() => setMobileSubNavOpen(false)}
            >
              <child.Icon size={16} strokeWidth={1.75} />
              <span>{t(child.labelKey)}</span>
            </NavLink>
          ))}
        </div>
      )}

      <SidebarUser />

      {tenant.ready && tenant.facilities.length > 0 && tenant.selectedFacilityId === null && (
        <div className="facility-required" role="status" data-testid="facility-required-banner">
          {t('shell.selectFacilityRequired')}
        </div>
      )}
    </div>
  );
}

export function useFacility() {
  return useTenant().selectedFacilityId;
}
