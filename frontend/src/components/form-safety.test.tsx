/**
 * Phase 142 — Clinical Form Safety Tests
 *
 * Proves:
 * - Form field density is compact
 * - Required fields have visual indicators
 * - Error states are accessible (role="alert")
 * - Button hierarchy is correct
 * - Form utilities exist and are correct
 * - Loading state prevents duplicate submission
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { render, screen } from '@testing-library/react';
import { Button, Input, Select, FieldShell } from './ui';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);
const FRONTEND_SRC = resolve(__dirname, '..');

function readCssFile(relativePath: string): string {
  return readCssFile_raw(join(FRONTEND_SRC, relativePath));
}

function readCssFile_raw(path: string): string {
  return readFileSync(path, 'utf-8');
}

describe('Phase 142 — Form Field Density', () => {
  it('field has compact gap', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.field\s*\{[^}]*gap:\s*var\(--space-1\)/);
  });

  it('field label uses uppercase', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.field__label\s*\{[^}]*text-transform:\s*uppercase/);
  });

  it('field label uses 11px font', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.field__label\s*\{[^}]*font-size:\s*var\(--text-xs\)/);
  });

  it('input has compact height (34px)', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.input\s*\{[^}]*height:\s*34px/);
  });

  it('field error uses 11px font', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.field__error\s*\{[^}]*font-size:\s*11px/);
  });

  it('field hint uses 11px font', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.field__hint\s*\{[^}]*font-size:\s*11px/);
  });
});

describe('Phase 142 — Form Utilities', () => {
  it('form-section utility exists', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toContain('.form-section');
    expect(ui).toContain('.form-section__title');
  });

  it('form-grid utility exists', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toContain('.form-grid');
    expect(ui).toContain('grid-template-columns: repeat(2, 1fr)');
  });

  it('form-actions utility exists', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toContain('.form-actions');
    expect(ui).toContain('.form-actions__spacer');
  });

  it('form-grid is responsive', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/@media.*max-width.*640px[^}]*\.form-grid\s*\{[^}]*grid-template-columns:\s*1fr/);
  });
});

describe('Phase 142 — Button Hierarchy', () => {
  it('primary button has clear styling', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.btn--primary\s*\{[^}]*background:\s*var\(--interactive-primary\)/);
  });

  it('danger button is visually distinct', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.btn--danger\s*\{[^}]*background:\s*var\(--red-600\)/);
  });

  it('ghost button is visually subordinate', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.btn--ghost\s*\{[^}]*background:\s*none/);
  });

  it('disabled button has reduced opacity', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.btn:disabled\s*\{[^}]*opacity:\s*0\.5/);
  });
});

describe('Phase 142 — Required Field Indicators', () => {
  it('required marker uses red color', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.field__required\s*\{[^}]*color:\s*var\(--red-500\)/);
  });

  it('FieldShell renders required asterisk', () => {
    render(
      <FieldShell label="Patient name" required>
        <input />
      </FieldShell>
    );
    expect(screen.getByText('*')).toBeInTheDocument();
    expect(screen.getByText('Patient name')).toBeInTheDocument();
  });
});

describe('Phase 142 — Error State Accessibility', () => {
  it('FieldShell error has role="alert"', () => {
    render(
      <FieldShell label="Email" error="Invalid email address">
        <input />
      </FieldShell>
    );
    expect(screen.getByRole('alert')).toHaveTextContent('Invalid email address');
  });

  it('Input shows aria-invalid when error', () => {
    render(<Input label="Email" error="Required" />);
    const input = screen.getByLabelText(/email/i);
    expect(input).toHaveAttribute('aria-invalid', 'true');
  });
});

describe('Phase 142 — Button Loading State', () => {
  it('loading button is disabled', () => {
    render(<Button loading>Save</Button>);
    const btn = screen.getByRole('button');
    expect(btn).toBeDisabled();
    expect(btn).toHaveAttribute('aria-busy', 'true');
  });

  it('disabled button is not interactive', () => {
    render(<Button disabled>Delete</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });
});

describe('Phase 142 — Input Focus Ring', () => {
  it('input has focus ring using interactive-primary', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.input:focus\s*\{[^}]*box-shadow:\s*var\(--focus-ring\)/);
  });

  it('error input has red focus ring', () => {
    const ui = readCssFile('components/ui.css');
    expect(ui).toMatch(/\.input--error:focus\s*\{[^}]*border-color:\s*var\(--red-500\)/);
  });
});
