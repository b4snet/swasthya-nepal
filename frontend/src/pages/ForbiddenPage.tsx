import { Link } from 'react-router-dom';
import { useI18n } from '../i18n/I18nProvider';

export function ForbiddenPage() {
  const { t } = useI18n();
  return (
    <div className="page page--narrow">
      <div className="state state--empty" style={{ minHeight: '60vh' }}>
        <h2>{t('forbidden.title')}</h2>
        <p className="muted">{t('forbidden.message')}</p>
        <div className="mt-4">
          <Link className="btn btn--primary" to="/">
            {t('forbidden.backToDashboard')}
          </Link>
        </div>
      </div>
    </div>
  );
}
