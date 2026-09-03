import { describe, expect, it } from 'vitest';
import {
  MODULES,
  filterModulesByEnablement,
  filterModulesByRole,
} from './modules';
import type { NavModule } from './modules';

const hasRole = (...roles: string[]) => (r: string) => roles.includes(r);

describe('filterModulesByRole', () => {
  it('keeps modules matching the role', () => {
    const result = filterModulesByRole(MODULES, hasRole('doctor'));
    const keys = result.map((m) => m.key);
    expect(keys).toContain('clinical');
    expect(keys).toContain('dashboard');
  });

  it('drops modules the role cannot access', () => {
    const result = filterModulesByRole(MODULES, hasRole('doctor'));
    const keys = result.map((m) => m.key);
    expect(keys).not.toContain('admin');
    expect(keys).not.toContain('finance');
    expect(keys).not.toContain('procurement');
  });

  it('keeps persistent dashboard regardless of role', () => {
    const result = filterModulesByRole(MODULES, hasRole('radiographer'));
    expect(result.find((m) => m.key === 'dashboard')).toBeDefined();
  });
});

describe('filterModulesByEnablement', () => {
  const sample: NavModule[] = [
    { key: 'dashboard', labelKey: 'nav.dashboard', Icon: (() => null) as never, roles: [], defaultTo: '/dashboard', routePrefix: '/dashboard', persistent: true, children: [] },
    { key: 'clinical', labelKey: 'module.clinical', Icon: (() => null) as never, roles: [], defaultTo: '/clinical', routePrefix: '/clinical', children: [] },
    { key: 'pharmacy', labelKey: 'module.pharmacy', Icon: (() => null) as never, roles: [], defaultTo: '/pharmacy', routePrefix: '/pharmacy', children: [] },
  ];

  it('drops modules that are not enabled', () => {
    const result = filterModulesByEnablement(sample, (key) => key === 'clinical');
    const keys = result.map((m) => m.key);
    expect(keys).toContain('dashboard');
    expect(keys).toContain('clinical');
    expect(keys).not.toContain('pharmacy');
  });

  it('keeps the persistent dashboard even when not explicitly enabled', () => {
    const result = filterModulesByEnablement(sample, () => false);
    expect(result.map((m) => m.key)).toEqual(['dashboard']);
  });

  it('keeps every module when all are enabled', () => {
    const result = filterModulesByEnablement(sample, () => true);
    expect(result).toHaveLength(sample.length);
  });

  it('is role-independent — it only applies entitlement state on top of role filtering', () => {
    const roleFiltered = filterModulesByRole(MODULES, hasRole('doctor'));
    const onlyClinicalDisabled = filterModulesByEnablement(
      roleFiltered,
      (key) => key !== 'pharmacy',
    );
    expect(onlyClinicalDisabled.map((m) => m.key)).toContain('clinical');
    expect(onlyClinicalDisabled.map((m) => m.key)).not.toContain('pharmacy');
  });
});