/**
 * I18nProvider — lightweight, zero-dependency locale context for the SPA.
 *
 * Phase 22 (National Scale) localization. Design decisions:
 *   - No i18n dependency: the catalog is a typed, flat dictionary; keys are
 *     the source of truth in `locales/en.ts` and the Nepali catalog is
 *     key-for-key parity-enforced by tests.
 *   - Locale choice persists in localStorage (`swasthya.locale`) and is
 *     reflected on `document.documentElement.lang`, which drives the
 *     Devanagari-first font stacks in `styles/tokens.css` via `html[lang='ne']`.
 *   - `useI18n()` falls back to `DEFAULT_I18N` (English) when no provider is
 *     mounted, so component tests that render isolated subtrees stay valid —
 *     the real application always mounts the provider in `main.tsx`.
 */
import { createContext, useCallback, useContext, useLayoutEffect, useMemo, useState, type ReactNode } from 'react';
import { messages as en, type MessageKey } from './locales/en';
import { messages as ne } from './locales/ne';

export type Locale = 'en' | 'ne';

const STORAGE_KEY = 'swasthya.locale';

const catalogs: Record<Locale, Record<MessageKey, string>> = { en, ne };

export interface I18nValue {
  locale: Locale;
  /** Translate a message key for the current locale. */
  t: (key: MessageKey) => string;
  setLocale: (locale: Locale) => void;
}

export const DEFAULT_I18N: I18nValue = {
  locale: 'en',
  t: (key) => en[key],
  setLocale: () => undefined,
};

const I18nContext = createContext<I18nValue>(DEFAULT_I18N);

function initialLocale(): Locale {
  try {
    const stored = globalThis.localStorage?.getItem(STORAGE_KEY);
    return stored === 'ne' ? 'ne' : 'en';
  } catch {
    return 'en';
  }
}

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<Locale>(initialLocale);

  const setLocale = useCallback((next: Locale) => {
    setLocaleState(next);
    try {
      globalThis.localStorage?.setItem(STORAGE_KEY, next);
    } catch {
      // Storage unavailable — the choice applies to this session only.
    }
  }, []);

  useLayoutEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const value = useMemo<I18nValue>(
    () => ({
      locale,
      t: (key) => catalogs[locale][key],
      setLocale,
    }),
    [locale, setLocale],
  );

  return <I18nContext.Provider value={value}>{children}</I18nContext.Provider>;
}

export function useI18n(): I18nValue {
  return useContext(I18nContext);
}
