import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { I18nProvider, useI18n } from './I18nProvider';
import { messages as en, type MessageKey } from './locales/en';
import { messages as ne } from './locales/ne';

function Probe() {
  const { locale, t, setLocale } = useI18n();
  const next: 'en' | 'ne' = locale === 'en' ? 'ne' : 'en';
  return (
    <div>
      <span data-testid="locale">{locale}</span>
      <span data-testid="dashboard">{t('nav.dashboard')}</span>
      <span data-testid="signout">{t('shell.signOut')}</span>
      <button type="button" onClick={() => setLocale(next)} aria-label={next === 'ne' ? 'नेपालीमा हेर्नुहोस्' : 'View in English'}>
        {locale === 'en' ? 'नेपाली' : 'EN'}
      </button>
    </div>
  );
}

const renderProbe = () =>
  render(
    <I18nProvider>
      <Probe />
    </I18nProvider>,
  );

describe('i18n provider (Phase 22 localization)', () => {
  it('defaults to English and renders English messages', () => {
    renderProbe();
    expect(screen.getByTestId('locale')).toHaveTextContent('en');
    expect(screen.getByTestId('dashboard')).toHaveTextContent('Dashboard');
    expect(screen.getByTestId('signout')).toHaveTextContent('Sign out');
  });

  it('switches to Nepali, renders Devanagari text, and sets the html lang attribute', async () => {
    const user = userEvent.setup();
    renderProbe();
    await user.click(screen.getByRole('button', { name: 'नेपालीमा हेर्नुहोस्' }));
    expect(screen.getByTestId('locale')).toHaveTextContent('ne');
    expect(screen.getByTestId('dashboard')).toHaveTextContent('ड्यासबोर्ड');
    expect(screen.getByTestId('signout')).toHaveTextContent('साइन आउट');
    expect(document.documentElement.lang).toBe('ne');
    // The toggle now offers English.
    expect(screen.getByRole('button', { name: 'View in English' })).toBeTruthy();
  });

  it('persists the choice and restores it on a fresh mount', async () => {
    const user = userEvent.setup();
    const first = renderProbe();
    await user.click(screen.getByRole('button', { name: 'नेपालीमा हेर्नुहोस्' }));
    expect(localStorage.getItem('swasthya.locale')).toBe('ne');
    first.unmount();
    renderProbe();
    expect(screen.getByTestId('locale')).toHaveTextContent('ne');
    expect(screen.getByTestId('dashboard')).toHaveTextContent('ड्यासबोर्ड');
  });

  it('enforces Nepali/English catalog parity (no silent English fallback in नेपाली)', () => {
    const enKeys = Object.keys(en) as MessageKey[];
    expect(enKeys.length).toBeGreaterThan(20);
    for (const key of enKeys) {
      expect(ne[key], `missing Nepali translation for "${key}"`).toBeTruthy();
      // A translation identical to the English source is a drift signal.
      expect(ne[key], `Nepali translation for "${key}" is identical to English`).not.toBe(en[key]);
    }
    // Nepali text is genuinely Devanagari script.
    expect(ne['nav.patients']).toMatch(/[\u0900-\u097F]/);
  });
});

// The Devanagari font-stack rule in styles/tokens.css (html[lang='ne']) is
// verified by a static gate script: frontend/scripts/verify-devanagari.mjs —
// Vitest stubs CSS imports, so the ACTUAL shipped file is checked at gate
// time instead (see NATSCALE evidence / DEVELOPMENT_LOG Phase 22 entry).
