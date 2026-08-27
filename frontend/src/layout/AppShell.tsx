import { useEffect, useRef, useState, useCallback } from 'react';
import { Outlet, useLocation, useNavigate } from 'react-router-dom';
import { CommandPalette } from '../components/CommandPalette';
import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';
import { useNetworkStatus } from '../hooks/useNetworkStatus';
import { useI18n } from '../i18n/I18nProvider';
import { Button, Dialog } from '../components/ui';
import { useAccess } from '../auth/useAccess';
import { ContextSurface } from '../components/contextual/ContextSurface';
import { lazy, Suspense } from 'react';
const ContextBar = lazy(() => import('../components/contextual/ContextBar').then(m => ({ default: m.ContextBar })));
import {
  LogOut,
  Globe,
  Building2,
  ChevronRight,
  MoreHorizontal,
  Bell,
  Search,
  PanelLeftClose,
  PanelLeft,
  UserRound,
  Lock,
} from 'lucide-react';
import {
  MODULES,
  getActiveModule,
  filterModulesByRole,
  type NavModule,
} from '../navigation/modules';
import { ModuleWorkspaceRail } from '../navigation/ModuleWorkspaceRail';
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
        {facilities[0]?.name ?? 'Hospital'}
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

// ── Sidebar: primary domain navigation only (no expanding children) ──
function Sidebar({
  modules,
  pathname,
  collapsed,
  onToggleCollapse,
  onDomainClick,
}: {
  modules: NavModule[];
  pathname: string;
  collapsed: boolean;
  onToggleCollapse: () => void;
  onDomainClick: (m: NavModule) => void;
}) {
  const { t } = useI18n();
  const navigate = useNavigate();

  const handleModuleClick = (m: NavModule) => {
    if (m.children.length === 0) {
      // Dashboard — navigate directly
      navigate(m.defaultTo);
    } else {
      // Domain with children — open contextual command surface
      onDomainClick(m);
    }
  };

  const isActive = (m: NavModule) =>
    pathname === m.routePrefix || pathname.startsWith(m.routePrefix + '/');

  return (
    <aside className={`sidebar ${collapsed ? 'sidebar--collapsed' : ''}`} role="navigation" aria-label="Main navigation">
      <div className="sidebar__items">
        {modules.map((m) => {
          const active = isActive(m);
          const isActiveDomain = active && !m.persistent && m.children.length > 0;

          return (
            <button
              key={m.key}
              type="button"
              className={`sidebar__item ${active ? 'sidebar__item--active' : ''} ${isActiveDomain ? 'sidebar__item--domain-active' : ''}`}
              onClick={() => handleModuleClick(m)}
              title={t(m.labelKey)}
              aria-label={t(m.labelKey)}
              aria-current={active && m.persistent ? 'page' : undefined}
              data-testid={`sidebar-${m.key}`}
            >
              <span className="sidebar__icon">
                <m.Icon size={18} strokeWidth={1.75} />
              </span>
              {!collapsed && (
                <span className="sidebar__label">{t(m.labelKey)}</span>
              )}
              {!collapsed && !m.persistent && m.children.length > 0 && active && (
                <ChevronRight size={14} className="sidebar__chevron sidebar__chevron--active" />
              )}
            </button>
          );
        })}
      </div>

      {/* Collapse toggle */}
      <button
        type="button"
        className="sidebar__collapse-btn"
        onClick={onToggleCollapse}
        title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
        aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
      >
        {collapsed ? <PanelLeft size={18} /> : <PanelLeftClose size={18} />}
      </button>
    </aside>
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

  // Patient workspace — show: Clinical > Patient > Workspace
  const patientMatch = pathname.match(/^\/clinical\/patients\/([^/]+)/);
  if (patientMatch) {
    const searchParams = new URLSearchParams(window.location.search);
    const ws = searchParams.get('ws') || 'overview';
    const wsLabels: Record<string, string> = {
      overview: 'Overview', encounters: 'Encounters', timeline: 'Timeline',
      diagnoses: 'Diagnoses', medications: 'Medications', lab: 'Laboratory',
      radiology: 'Radiology', admissions: 'Admissions', referrals: 'Referrals',
      appointments: 'Appointments', documents: 'Documents',
    };
    return (
      <nav className="breadcrumbs" aria-label="Breadcrumb">
        <span className="breadcrumbs__item breadcrumbs__item--muted">{t('module.clinical')}</span>
        <ChevronRight size={12} className="breadcrumbs__sep" />
        <span className="breadcrumbs__item breadcrumbs__item--muted">{t('nav.patients')}</span>
        <ChevronRight size={12} className="breadcrumbs__sep" />
        <span className="breadcrumbs__item breadcrumbs__item--current">{wsLabels[ws] || ws}</span>
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

// ── Patient Context Strip ──
// Compact persistent patient identity bar between workspace and content.
// Only renders when a patient ID is in the URL.
// Does NOT fetch patient data — this is a lightweight shell-level indicator.
// The actual patient workspace (PatientWorkspace.tsx) renders the full header.
function PatientContextStrip({ patientId }: { patientId: string }) {
  return (
    <div className="patient-strip" role="status" aria-label="Active patient context">
      <span className="patient-strip__icon">
        <UserRound size={13} />
      </span>
      <span className="patient-strip__label">Patient</span>
      <span className="patient-strip__id mono">{patientId.slice(0, 8)}</span>
      <span className="patient-strip__lock" title="Patient context locked">
        <Lock size={11} />
      </span>
    </div>
  );
}

// ── Main shell ──
export function AppShell() {
  const tenant = useTenant();
  const { t } = useI18n();
  const location = useLocation();
  const navigate = useNavigate();
  const network = useNetworkStatus();
  const access = useAccess();

  const allModules = filterModulesByRole(MODULES, tenant.hasRole);
  const dashboardModule = allModules.find((m) => m.persistent);
  const visibleModules = allModules.filter((m) => !m.persistent);
  const activeModule = getActiveModule(location.pathname);

  // Extract patient context from URL
  const patientMatch = location.pathname.match(/^\/clinical\/patients\/([^/]+)/);
  const activePatientId = patientMatch?.[1] ?? null;

  // Sidebar collapsed state
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  // Domain command surface state
  const [activeDomain, setActiveDomain] = useState<NavModule | null>(null);
  const handleDomainClick = useCallback((m: NavModule) => {
    setActiveDomain((prev) => (prev?.key === m.key ? null : m));
  }, []);
  const closeDomainSurface = useCallback(() => setActiveDomain(null), []);

  // Close domain surface on route change
  useEffect(() => {
    setActiveDomain(null);
  }, [location.pathname]);

  // Mobile bottom nav
  const mobileModules = visibleModules.slice(0, 4);
  const moreModules = visibleModules.slice(4);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

  // Build the sidebar module list: Dashboard first, then other modules
  const sidebarModules: NavModule[] = dashboardModule
    ? [dashboardModule, ...visibleModules]
    : visibleModules;

  return (
    <div className={`app-shell ${sidebarCollapsed ? 'app-shell--collapsed' : ''}`}>
      <CommandPalette
        activePatientId={patientMatch?.[1] ?? null}
        activePatientName={undefined}
        activePatientMrn={undefined}
      />
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
              <rect width="28" height="28" rx="6" fill="#0f766e"/>
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

      {/* ── Global context bar — critical items always visible ── */}
      {tenant.selectedFacilityId && (
        <Suspense fallback={null}><ContextBar /></Suspense>
      )}

      {/* ── Patient context strip — persistent patient identity ── */}
      {activePatientId && (
        <PatientContextStrip patientId={activePatientId} />
      )}

      <div className="app-body">
        {/* ── Primary domain sidebar (no expanding children) ── */}
        <Sidebar
          modules={sidebarModules}
          pathname={location.pathname}
          collapsed={sidebarCollapsed}
          onToggleCollapse={() => setSidebarCollapsed((v) => !v)}
          onDomainClick={handleDomainClick}
        />

        {/* ── Module workspace rail — vertical contextual navigation ── */}
        {!sidebarCollapsed && (
          <ModuleWorkspaceRail activeModule={activeModule} pathname={location.pathname} />
        )}

        {/* ── Content ── */}
        <main className="app-content" id="content" tabIndex={-1}>
          {/* Context surface — contextual action launcher */}
          {activeDomain && (
            <ContextSurface
              module={activeDomain}
              open={!!activeDomain}
              onClose={closeDomainSurface}
            />
          )}
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
            <dashboardModule.Icon size={20} strokeWidth={1.75} />
            <span>{t(dashboardModule.labelKey)}</span>
          </button>
        )}
        {mobileModules.map((m) => (
          <button
            key={m.key}
            type="button"
            className={`bottom-nav__item ${activeModule?.key === m.key ? 'bottom-nav__item--active' : ''}`}
            onClick={() => { navigate(m.defaultTo); setMobileMenuOpen(false); }}
          >
            <m.Icon size={20} strokeWidth={1.75} />
            <span>{t(m.labelKey)}</span>
          </button>
        ))}
        {moreModules.length > 0 && (
          <button
            type="button"
            className="bottom-nav__item"
            onClick={() => setMobileMenuOpen((v) => !v)}
          >
            <MoreHorizontal size={20} strokeWidth={1.75} />
            <span>{t('shell.more')}</span>
          </button>
        )}
      </nav>

      {/* ── Mobile module drawer ── */}
      {mobileMenuOpen && (
        <div className="mobile-sheet" role="menu" aria-label="More modules">
          {moreModules.map((m) => (
            <button
              key={m.key}
              type="button"
              className="mobile-sheet__item"
              onClick={() => { navigate(m.defaultTo); setMobileMenuOpen(false); }}
            >
              <m.Icon size={18} strokeWidth={1.75} />
              <span>{t(m.labelKey)}</span>
            </button>
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
