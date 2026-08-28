/**
 * Phase 141 — Clinical Data Presentation Tests
 *
 * Proves:
 * - data-table has correct density (compact padding)
 * - ClinicalValue renders with correct structure
 * - Clinical data CSS utilities exist and are correct
 * - Status semantics are present
 * - Identifier treatment uses mono font
 * - Numeric values use tabular-nums
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const FRONTEND_SRC = resolve(__dirname, '..');

function readCssFile(relativePath: string): string {
  return readFileSync(join(FRONTEND_SRC, relativePath), 'utf-8');
}

describe('Phase 141 — Data Table Density', () => {
  it('data-table th has compact padding', () => {
    const base = readCssFile('styles/base.css');
    // Should use space-1-5 or space-2 padding for th, not space-2-5 or space-3
    expect(base).toMatch(/\.data-table th\s*\{[^}]*padding:\s*var\(--space-1-5\)/);
  });

  it('data-table td has compact padding', () => {
    const base = readCssFile('styles/base.css');
    // Should use space-2 padding for td
    expect(base).toMatch(/\.data-table td\s*\{[^}]*padding:\s*var\(--space-2\)/);
  });

  it('data-table uses small font size for td', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toMatch(/\.data-table td\s*\{[^}]*font-size:\s*var\(--text-sm\)/);
  });

  it('data-table header uses 11px font', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toMatch(/\.data-table th\s*\{[^}]*font-size:\s*11px/);
  });

  it('data-table hover uses teal-50', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toMatch(/\.data-table tbody tr:hover\s*\{[^}]*background:\s*var\(--teal-50\)/);
  });
});

describe('Phase 141 — ClinicalValue Density', () => {
  it('clinical-value uses compact padding', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.clinical-value\s*\{[^}]*padding:\s*var\(--space-1-5\)/);
  });

  it('clinical-value data uses small font', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.clinical-value__data\s*\{[^}]*font-size:\s*var\(--text-sm\)/);
  });

  it('clinical-value data uses tabular-nums', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.clinical-value__data\s*\{[^}]*font-variant-numeric:\s*tabular-nums/);
  });
});

describe('Phase 141 — Clinical Data Utilities', () => {
  it('base.css defines clinical-id utility', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toContain('.clinical-id');
    expect(base).toContain('var(--font-mono)');
  });

  it('base.css defines clinical-num utility', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toContain('.clinical-num');
    expect(base).toContain('text-align: right');
    expect(base).toContain('font-variant-numeric: tabular-nums');
  });

  it('base.css defines clinical-status utility', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toContain('.clinical-status');
    expect(base).toContain('.clinical-status--success');
    expect(base).toContain('.clinical-status--warning');
    expect(base).toContain('.clinical-status--danger');
    expect(base).toContain('.clinical-status--info');
    expect(base).toContain('.clinical-status--neutral');
  });

  it('clinical-status uses uppercase letter-spacing', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toMatch(/\.clinical-status\s*\{[^}]*text-transform:\s*uppercase/);
    expect(base).toMatch(/\.clinical-status\s*\{[^}]*letter-spacing:\s*0\.03em/);
  });

  it('clinical-status uses 11px font size', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toMatch(/\.clinical-status\s*\{[^}]*font-size:\s*11px/);
  });
});

describe('Phase 141 — Tabular Numerals Consistency', () => {
  it('base.css defines tabular-nums utility', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toContain('.tabular-nums');
    expect(base).toContain('font-variant-numeric: tabular-nums');
  });

  it('mono class includes tabular-nums', () => {
    const base = readCssFile('styles/base.css');
    expect(base).toMatch(/\.mono\s*\{[^}]*font-variant-numeric:\s*tabular-nums/);
  });
});
