import { Link } from 'react-router-dom';
import { useI18n } from '../../i18n/I18nProvider';
import { MessageSquare, Bell } from 'lucide-react';
import './module-dashboards.css';

const actions = [
  { to: '/communications/notifications', icon: Bell, label: 'Notifications' },
  { to: '/communications/messages', icon: MessageSquare, label: 'Messages' },
];

export function CommunicationsDashboard() {
  const { t } = useI18n();
  return (
    <div className="module-dash">
      <div className="module-dash__header">
        <MessageSquare size={24} strokeWidth={1.5} />
        <div>
          <h1>{t('module.communications')}</h1>
          <p className="module-dash__subtitle">Notifications, messaging, and alerts</p>
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
