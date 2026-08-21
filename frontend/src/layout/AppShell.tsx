import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider';
import { useTenant } from '../context/TenantContext';
import { useNetworkStatus } from '../hooks/useNetworkStatus';
import { ADMIN_ROLES, AUDIT_ROLES, BILLING_ROLES, QUEUE_ROLES } from '../auth/roles';
import { useI18n } from '../i18n/I18nProvider';
import type { MessageKey } from '../i18n/locales/en';
import { Button, Dialog } from '../components/ui';
import {
  LayoutDashboard,
  Users,
  CalendarDays,
  ListOrdered,
  Pill,
  Boxes,
  ShoppingCart,
  WalletCards,
  Landmark,
  Receipt,
  CalendarClock,
  ChartNoAxesCombined,
  Bell,
  ScanLine,
  Crosshair,
  Siren,
  HeartPulse,
  Stethoscope,
  Upload,
  Scissors,
  Droplets,
  ClipboardList,
  FileText,
  Bed,
  PanelsTopLeft,
  ShieldCheck,
  Settings,
  ChevronRight,
  LogOut,
  Globe,
  Building2,
  MoreHorizontal,
  type LucideIcon,
} from 'lucide-react';
import './shell.css';

// ── Navigation with Lucide icons ──
const NAV: Array<{ to: string; labelKey: MessageKey; Icon: LucideIcon; roles: string[]; group?: string }> = [
  { to: '/', labelKey: 'nav.dashboard', Icon: LayoutDashboard, roles: [], group: 'overview' },
  { to: '/patients', labelKey: 'nav.patients', Icon: Users, roles: [], group: 'clinical' },
  { to: '/patients/import', labelKey: 'nav.patientImport', Icon: Upload, roles: [], group: 'clinical' },
  { to: '/appointments', labelKey: 'nav.appointments', Icon: CalendarDays, roles: [], group: 'clinical' },
  { to: '/queue', labelKey: 'nav.queue', Icon: ListOrdered, roles: [...QUEUE_ROLES], group: 'clinical' },
  { to: '/pharmacy', labelKey: 'nav.pharmacy', Icon: Pill, roles: [], group: 'operations' },
  { to: '/inventory', labelKey: 'nav.inventory', Icon: Boxes, roles: [], group: 'operations' },
  { to: '/procurement', labelKey: 'nav.procurement', Icon: ShoppingCart, roles: [], group: 'operations' },
  { to: '/billing', labelKey: 'nav.billing', Icon: WalletCards, roles: [...BILLING_ROLES], group: 'finance' },
  { to: '/finance', labelKey: 'nav.finance', Icon: Landmark, roles: [], group: 'finance' },
  { to: '/budgets', labelKey: 'nav.budgets', Icon: ChartNoAxesCombined, roles: [], group: 'finance' },
  { to: '/expenses', labelKey: 'nav.expenses', Icon: Receipt, roles: [], group: 'finance' },
  { to: '/financial-periods', labelKey: 'nav.financialPeriods', Icon: CalendarClock, roles: [], group: 'finance' },
  { to: '/analytics', labelKey: 'nav.analytics', Icon: ChartNoAxesCombined, roles: [], group: 'insights' },
  { to: '/notifications', labelKey: 'nav.notifications', Icon: Bell, roles: [], group: 'insights' },
  { to: '/forms', labelKey: 'nav.forms', Icon: FileText, roles: [], group: 'clinical' },
  { to: '/physician-scheduling', labelKey: 'nav.physicianScheduling', Icon: Stethoscope, roles: [], group: 'clinical' },
  { to: '/beds', labelKey: 'nav.beds', Icon: Bed, roles: [], group: 'clinical' },
  { to: '/emergency', labelKey: 'nav.emergency', Icon: Siren, roles: [], group: 'clinical' },
  { to: '/icu', labelKey: 'nav.icu', Icon: HeartPulse, roles: [], group: 'clinical' },
  { to: '/ot', labelKey: 'nav.ot', Icon: Scissors, roles: [], group: 'clinical' },
  { to: '/blood-bank', labelKey: 'nav.bloodBank', Icon: Droplets, roles: [], group: 'clinical' },
  { to: '/nursing', labelKey: 'nav.nursing', Icon: ClipboardList, roles: [], group: 'clinical' },
  { to: '/radiology', labelKey: 'nav.radiology', Icon: ScanLine, roles: [], group: 'clinical' },
  { to: '/oncology', labelKey: 'nav.oncology', Icon: Crosshair, roles: [], group: 'clinical' },
  { to: '/portal', labelKey: 'nav.portal', Icon: PanelsTopLeft, roles: [], group: 'insights' },
  { to: '/audit', labelKey: 'nav.audit', Icon: ShieldCheck, roles: [...AUDIT_ROLES], group: 'admin' },
  { to: '/admin', labelKey: 'nav.admin', Icon: Settings, roles: [...ADMIN_ROLES], group: 'admin' },
];

function allowed(roles: string[], hasRole: (r: string) => boolean) {
  return roles.length === 0 || roles.some((r) => hasRole(r));
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
        title={user?.email ?? ''}
        onClick={() => setOpen((v) => !v)}
        aria-expanded={open}
        aria-haspopup="menu"
        data-testid="user-menu-trigger"
      >
        {user?.email?.slice(0, 2).toUpperCase()}
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

// ── Main shell ──
export function AppShell() {
  const { selectedFacilityId } = useTenant();
  const hasRole = useTenant().hasRole;
  const { t } = useI18n();
  const location = useLocation();
  const [mobileMoreOpen, setMobileMoreOpen] = useState(false);

  const visible = NAV.filter((n) => allowed(n.roles, hasRole)).map((n) => ({ ...n, label: t(n.labelKey) }));
  const primary = visible.slice(0, 4);
  const rest = visible.slice(4);
  const network = useNetworkStatus();

  return (
    <div className="app-shell">
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
        </div>
        <div className="app-header__right">
          <FacilitySwitcher />
          <LanguageToggle />
          <UserMenu />
        </div>
      </header>

      <div className="app-body">
        {/* ── Sidebar ── */}
        <aside className="app-sidebar" aria-label={t('shell.primary')}>
          <nav className="sidebar-nav" role="navigation">
            {visible.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.to === '/'}
                className={({ isActive }) => `sidebar-link ${isActive ? 'sidebar-link--active' : ''}`}
              >
                <item.Icon size={18} strokeWidth={1.75} />
                <span className="sidebar-link__label">{item.label}</span>
              </NavLink>
            ))}
          </nav>
          <div className="sidebar-footer">
            <SidebarUser />
          </div>
        </aside>

        {/* ── Content ── */}
        <main className="app-content" id="content" tabIndex={-1}>
          <div key={location.pathname} className="page-transition">
            <Outlet />
          </div>
        </main>
      </div>

      {/* ── Mobile bottom nav ── */}
      <nav className="bottom-nav" aria-label={t('shell.primary')}>
        {primary.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to === '/'}
            className={({ isActive }) => `bottom-nav__item ${isActive ? 'bottom-nav__item--active' : ''}`}
          >
            <item.Icon size={20} strokeWidth={1.75} />
            <span>{item.label}</span>
          </NavLink>
        ))}
        {rest.length > 0 && (
          <button className="bottom-nav__item" aria-expanded={mobileMoreOpen} onClick={() => setMobileMoreOpen((v) => !v)}>
            <MoreHorizontal size={20} strokeWidth={1.75} />
            <span>{t('shell.more')}</span>
          </button>
        )}
      </nav>

      {mobileMoreOpen && (
        <div className="mobile-sheet" role="menu" aria-label={t('shell.moreDestinations')}>
          {rest.map((item) => (
            <NavLink key={item.to} to={item.to} className="mobile-sheet__item" onClick={() => setMobileMoreOpen(false)}>
              <item.Icon size={18} strokeWidth={1.75} />
              <span>{item.label}</span>
              <ChevronRight size={14} className="mobile-sheet__chevron" />
            </NavLink>
          ))}
        </div>
      )}

      {!selectedFacilityId && (
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
