import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';

// Node >= 22 defines experimental `localStorage`/`sessionStorage` globals (they
// return undefined unless --localstorage-file is passed). vitest 2.x's jsdom
// population then drops the real jsdom Storage instances because the keys
// already exist on `global`. Restore working Storage instances when missing.
function createStorage(): Storage {
  const store = new Map<string, string>();
  return {
    get length() {
      return store.size;
    },
    clear: () => store.clear(),
    getItem: (key: string) => (store.has(key) ? store.get(key)! : null),
    key: (index: number) => Array.from(store.keys())[index] ?? null,
    removeItem: (key: string) => {
      store.delete(key);
    },
    setItem: (key: string, value: string) => {
      store.set(key, String(value));
    },
  } as Storage;
}

function ensureStorage(): void {
  if (typeof globalThis.localStorage === 'undefined') {
    try {
      Object.defineProperty(globalThis, 'localStorage', {
        value: createStorage(),
        configurable: true,
        writable: true,
      });
    } catch {
      // Non-configurable host global — leave it; tests that need storage will
      // be adapted rather than masking the environment.
    }
  }
  if (typeof globalThis.sessionStorage === 'undefined') {
    try {
      Object.defineProperty(globalThis, 'sessionStorage', {
        value: createStorage(),
        configurable: true,
        writable: true,
      });
    } catch {
      // See above.
    }
  }
}

ensureStorage();

afterEach(() => {
  cleanup();
  sessionStorage.clear();
  localStorage.clear();
});
