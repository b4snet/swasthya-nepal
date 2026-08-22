import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { BarChart3, ChartNoAxesCombined, Activity, FileText } from 'lucide-react';
import './module-dashboards.css';

const actions = [
  { to: '/reports/analytics', icon: ChartNoAxesCombined, label: 'Analytics' },
  { to: '/reports/operations', icon: Activity, label: 'Operations Center' },
  { to: '/reports/documents', icon: FileText, label: 'Document Center' },
];

export function ReportsDashboard() {
  const { t } = useI18n();
  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <BarChart3 size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.reports')}</h1>
          <p className="module-dash__subtitle">Analytics, operational insights, and documents</p>
        </div>
      </div>
      <div className="module-dash__grid">
        {actions.map((a) => (
          <Link key={a.to} to={a.to} className="module-dash__card">
            <a.icon size={20} strokeWidth={1.75} />
            <span>{a.label}</span>
          </Link>
        ))}
      </div>
    </div>
  );
}
