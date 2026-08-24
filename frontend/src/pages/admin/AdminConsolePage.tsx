import { useState, useEffect, useCallback } from 'react';
import { Link } from 'react-router-dom';
import { useTenant } from '../../context/TenantContext';
import { useI18n } from '../../i18n/I18nProvider';
import { adminFacilitySettingsApi, modulesApi } from '../../api/endpoints';
import { Button } from '../../components/ui';
import {
  Building2,
  Users,
  Shield,
  UserCog,
  Stethoscope,
  HeartPulse,
  Pill,
  Settings,
  Palette,
  Hash,
  FileText,
  Bell,
  LayoutGrid,
  Bed,
  ChevronRight,
  CheckCircle,
  RefreshCw,
} from 'lucide-react';
import './admin-console.css';

interface ModuleInfo {
  code: string;
  name: string;
  enabled: boolean;
}

interface StatItem {
  label: string;
  value: string | number;
  icon: typeof Users;
  color: string;
  bg: string;
  link?: string;
}

const ADMIN_SECTIONS = [
  {
    title: 'Organization',
    items: [
      { to: '/admin/users', label: 'Users', icon: Users, desc: 'Manage user accounts and access', permission: 'user:view' },
      { to: '/admin/roles', label: 'Roles & Permissions', icon: Shield, desc: 'Configure roles and access control', permission: 'role:view' },
      { to: '/admin/staff', label: 'Staff Directory', icon: UserCog, desc: 'Manage staff profiles and assignments', permission: 'staff:view' },
    ],
  },
  {
    title: 'Clinical',
    items: [
      { to: '/admin/departments', label: 'Departments', icon: Stethoscope, desc: 'Configure hospital departments', permission: 'department:view' },
      { to: '/admin/services', label: 'Services', icon: HeartPulse, desc: 'Manage clinical services catalog', permission: 'service:view' },
      { to: '/admin/medications', label: 'Medications', icon: Pill, desc: 'Medication catalog and inventory settings', permission: 'medication:view' },
    ],
  },
  {
    title: 'Infrastructure',
    items: [
      { to: '/beds', label: 'Wards, Rooms & Beds', icon: Bed, desc: 'Bed occupancy and ward configuration', permission: 'admission:view' },
    ],
  },
  {
    title: 'Configuration',
    items: [
      { to: '/admin/settings', label: 'Facility Settings', icon: Settings, desc: 'Key-value configuration for facilities', permission: 'facility:manage' },
      { to: '/admin/branding', label: 'Hospital Branding', icon: Palette, desc: 'Logo, colors, documents, financial settings', permission: 'branding:manage' },
      { to: '/numbering', label: 'Numbering System', icon: Hash, desc: 'Document numbering prefixes and sequences', permission: 'numbering:view' },
    ],
  },
  {
    title: 'Communication',
    items: [
      { to: '/communications', label: 'Templates', icon: FileText, desc: 'Communication templates for notifications', permission: 'notification:manage' },
      { to: '/notifications', label: 'Notification Platform', icon: Bell, desc: 'Mass notification campaigns and emergency alerts', permission: 'notification:view' },
    ],
  },
];

export function AdminConsolePage() {
  const { selectedFacilityId, facilities, hasRole, organizationId } = useTenant();
  const { } = useI18n();

  const [modules, setModules] = useState<ModuleInfo[]>([]);
  const [stats, setStats] = useState<Record<string, number> | null>(null);
  const [, setLoading] = useState(true);

  const fetchModules = useCallback(async () => {
    try {
      const res = await modulesApi.enabled();
      const data = res as unknown as { modules: ModuleInfo[] };
      setModules(data.modules ?? []);
    } catch {
      // Module catalog not available
    }
  }, []);

  const fetchStats = useCallback(async () => {
    if (!organizationId || !selectedFacilityId) return;
    try {
      const [unread, settings] = await Promise.all([
        import('../../api/endpoints').then((m) =>
          m.realtimeApi.unreadCount(selectedFacilityId).catch(() => ({ count: 0 })),
        ),
        adminFacilitySettingsApi.list(selectedFacilityId).catch(() => ({})),
      ]);

      setStats({
        notifications: (unread as unknown as { count: number }).count ?? 0,
        settings: Object.keys(settings as Record<string, unknown>).length,
      });
    } catch {
      // Stats optional
    }
  }, [organizationId, selectedFacilityId]);

  useEffect(() => {
    setLoading(true);
    Promise.all([fetchModules(), fetchStats()]).then(() => setLoading(false));
  }, [fetchModules, fetchStats]);

  const facilityStats: StatItem[] = [
    {
      label: 'Facilities',
      value: facilities.length,
      icon: Building2,
      color: '#2563eb',
      bg: '#eff6ff',
    },
    {
      label: 'Active Modules',
      value: modules.filter((m) => m.enabled).length,
      icon: LayoutGrid,
      color: '#059669',
      bg: '#ecfdf5',
    },
    {
      label: 'Unread Notifications',
      value: stats?.notifications ?? 0,
      icon: Bell,
      color: '#7c3aed',
      bg: '#f5f3ff',
      link: '/notifications',
    },
    {
      label: 'Facility Settings',
      value: stats?.settings ?? 0,
      icon: Settings,
      color: '#d97706',
      bg: '#fffbeb',
      link: '/admin/settings',
    },
  ];

  return (
    <div className="ac-page">
      {/* Header */}
      <header className="ac-header">
        <div className="ac-header__title">
          <LayoutGrid size={24} />
          <div>
            <h1>Hospital Administration</h1>
            <p className="ac-header__subtitle">
              Manage your hospital configuration, users, and system settings
            </p>
          </div>
        </div>
        <div className="ac-header__actions">
          <Button onClick={() => { fetchModules(); fetchStats(); }} variant="ghost" size="sm">
            <RefreshCw size={16} />
            Refresh
          </Button>
        </div>
      </header>

      {/* Facility info */}
      <div className="ac-facility-bar">
        <Building2 size={16} />
        <span className="ac-facility-bar__label">Hospital:</span>
        <span className="ac-facility-bar__value">{facilities[0]?.organizationCode ?? '—'}</span>
        <span className="ac-facility-bar__sep">·</span>
        <span className="ac-facility-bar__label">Facility:</span>
        <span className="ac-facility-bar__value">{facilities.find((f) => f.id === selectedFacilityId)?.name ?? 'All'}</span>
      </div>

      {/* Stats */}
      <div className="ac-stats">
        {facilityStats.map((stat) => {
          const Icon = stat.icon;
          const content = (
            <>
              <div className="ac-stat-card" style={{ '--stat-color': stat.color, '--stat-bg': stat.bg } as React.CSSProperties}>
                <div className="ac-stat-card__icon">
                  <Icon size={20} />
                </div>
                <div className="ac-stat-card__content">
                  <span className="ac-stat-card__value">{stat.value}</span>
                  <span className="ac-stat-card__label">{stat.label}</span>
                </div>
              </div>
            </>
          );

          return stat.link ? (
            <Link key={stat.label} to={stat.link} className="ac-stat-link">
              {content}
            </Link>
          ) : (
            <div key={stat.label}>{content}</div>
          );
        })}
      </div>

      {/* Module status */}
      {modules.length > 0 && (
        <div className="ac-modules">
          <h3 className="ac-section-title">Active Modules</h3>
          <div className="ac-module-grid">
            {modules.filter((m) => m.enabled).map((mod) => (
              <div key={mod.code} className="ac-module-chip">
                <CheckCircle size={14} />
                <span>{mod.name}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Admin sections */}
      <div className="ac-sections">
        {ADMIN_SECTIONS.map((section) => {
          const visibleItems = section.items.filter((item) =>
            item.permission ? hasRole(item.permission.split(':')[0] + ':view', item.permission) : true,
          );

          if (visibleItems.length === 0) return null;

          return (
            <div key={section.title} className="ac-section">
              <h3 className="ac-section-title">{section.title}</h3>
              <div className="ac-section-grid">
                {visibleItems.map((item) => {
                  const Icon = item.icon;
                  return (
                    <Link key={item.to} to={item.to} className="ac-section-card">
                      <div className="ac-section-card__icon">
                        <Icon size={22} />
                      </div>
                      <div className="ac-section-card__content">
                        <span className="ac-section-card__label">{item.label}</span>
                        <span className="ac-section-card__desc">{item.desc}</span>
                      </div>
                      <ChevronRight size={18} className="ac-section-card__arrow" />
                    </Link>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
