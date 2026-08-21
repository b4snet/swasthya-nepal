import { Link, Outlet, useLocation } from 'react-router-dom';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { ADMIN_ROLES } from '../../auth/roles';
import './admin.css';

const ADMIN_NAV = [
  { to: '/admin/users', labelKey: 'admin.nav.users' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/roles', labelKey: 'admin.nav.roles' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/staff', labelKey: 'admin.nav.staff' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/departments', labelKey: 'admin.nav.departments' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/services', labelKey: 'admin.nav.services' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/medications', labelKey: 'admin.nav.medications' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/settings', labelKey: 'admin.nav.settings' as const, roles: [...ADMIN_ROLES] },
  { to: '/admin/branding', labelKey: 'admin.nav.branding' as const, roles: [...ADMIN_ROLES] },
];

export function AdminLayout() {
  const { hasRole } = useTenant();
  const { t } = useI18n();
  const location = useLocation();

  if (!hasRole(...ADMIN_ROLES)) {
    return (
      <div className="page">
        <div className="state state--empty" style={{ minHeight: '60vh' }}>
          <h2>{t('forbidden.title')}</h2>
          <p className="muted">{t('forbidden.message')}</p>
          <Link className="btn btn--primary mt-4" to="/">{t('forbidden.backToDashboard')}</Link>
        </div>
      </div>
    );
  }

  return (
    <div className="page">
      <div className="page__head">
        <div className="page__title">
          <h1>{t('admin.title')}</h1>
          <span className="page__sub">{t('admin.subtitle')}</span>
        </div>
      </div>
      <div className="admin-layout">
        <nav className="admin-tabs" role="tablist" aria-label={t('admin.title')}>
          {ADMIN_NAV.filter((n) => hasRole(...n.roles)).map((item) => {
            const active = location.pathname.startsWith(item.to);
            return (
              <Link
                key={item.to}
                to={item.to}
                role="tab"
                aria-selected={active}
                className={`tabs__tab ${active ? 'tabs__tab--active' : ''}`}
              >
                {t(item.labelKey)}
              </Link>
            );
          })}
        </nav>
        <div className="admin-content">
          <Outlet />
        </div>
      </div>
    </div>
  );
}
