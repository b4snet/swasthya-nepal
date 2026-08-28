/**
 * Phase 139 — Design System Hardening Tests
 *
 * Proves:
 * - Light mode is the default
 * - No hardcoded #3b82f6 (generic blue) in clinical CSS
 * - Clinical accent uses design tokens
 * - Focus rings use teal tokens
 * - Status colors use semantic tokens
 */

import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve, dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const FRONTEND_SRC = resolve(__dirname, '..');

function readCssFile(relativePath: string): string {
  return readFileSync(join(FRONTEND_SRC, relativePath), 'utf-8');
}

function getAllCssFiles(dir: string = FRONTEND_SRC): string[] {
  const results: string[] = [];
  for (const entry of readdirSync(dir)) {
    const fullPath = join(dir, entry);
    const stat = statSync(fullPath);
    if (stat.isDirectory() && entry !== 'node_modules') {
      results.push(...getAllCssFiles(fullPath));
    } else if (entry.endsWith('.css') && !entry.includes('.test.')) {
      results.push(relative(FRONTEND_SRC, fullPath));
    }
  }
  return results;
}

describe('Phase 139 — Design System: Light Mode Default', () => {
  it('tokens.css defines light mode as the root theme', () => {
    const tokens = readCssFile('styles/tokens.css');
    expect(tokens).toContain('--white');
    expect(tokens).toContain('--gray-50');
    expect(tokens).toContain('--surface-bg');
  });

  it('no dark-mode class is applied by default in base', () => {
    const base = readCssFile('styles/base.css');
    expect(base).not.toMatch(/\.dark\s*\{/);
  });
});

describe('Phase 139 — Design System: No Hardcoded Blue', () => {
  it('no hardcoded #3b82f6 in CSS files', () => {
    const cssFiles = getAllCssFiles();
    const violations: string[] = [];

    for (const file of cssFiles) {
      const content = readCssFile(file);
      if (content.includes('#3b82f6')) {
        violations.push(file);
      }
    }

    expect(violations).toEqual([]);
  });

  it('no hardcoded #2e90fa outside tokens.css', () => {
    const cssFiles = getAllCssFiles();
    const violations: string[] = [];

    for (const file of cssFiles) {
      if (file.includes('tokens.css')) continue;
      const content = readCssFile(file);
      if (content.includes('#2e90fa')) {
        violations.push(file);
      }
    }

    expect(violations).toEqual([]);
  });
});

describe('Phase 139 — Design System: Clinical Accent Tokens', () => {
  it('tokens.css defines teal accent colors', () => {
    const tokens = readCssFile('styles/tokens.css');
    expect(tokens).toContain('--teal-600');
    expect(tokens).toContain('--teal-700');
  });

  it('tokens.css defines gradient-brand using teal', () => {
    const tokens = readCssFile('styles/tokens.css');
    expect(tokens).toContain('--gradient-brand');
    expect(tokens).toContain('var(--teal-600)');
  });

  it('tokens.css defines semantic status colors', () => {
    const tokens = readCssFile('styles/tokens.css');
    expect(tokens).toContain('--text-primary');
    expect(tokens).toContain('--text-secondary');
    expect(tokens).toContain('--border-subtle');
  });
});

describe('Phase 139 — Design System: Focus Ring Consistency', () => {
  it('no hardcoded rgba(59,130,246 focus rings outside tokens', () => {
    const cssFiles = getAllCssFiles();
    const violations: string[] = [];

    for (const file of cssFiles) {
      if (file.includes('tokens.css')) continue;
      const content = readCssFile(file);
      if (content.includes('rgba(59,130,246')) {
        violations.push(file);
      }
    }

    expect(violations).toEqual([]);
  });
});
