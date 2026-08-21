import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { App } from './App';
import { I18nProvider } from './i18n/I18nProvider';
import './styles/tokens.css';
import './styles/base.css';
import './styles/dark.css';
import './components/ui.css';
import './layout/shell.css';
import './pages/pages.css';
import './styles/dashboard.css';

// PWA service worker — register in production only
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      // Service worker registration failed silently — app works without it
    });
  });
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      {/* Phase 22 localization: the locale provider wraps the whole SPA so
          the app shell, login, and shared chrome render in English or Nepali
          (Devanagari), with the choice persisted and the html lang attribute
          driving the Devanagari-first font stacks (tokens.css). */}
      <I18nProvider>
        <App />
      </I18nProvider>
    </BrowserRouter>
  </StrictMode>,
);
