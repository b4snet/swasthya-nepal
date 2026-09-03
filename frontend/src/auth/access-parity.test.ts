/**
 * RBAC PERMISSION-CODE PARITY INVARIANT (SECURITY.md §6, MASTER_RULES.md §9.5).
 *
 * The canonical permission vocabulary lives in the BACKEND seeder
 * (backend/database/seeders/RolePermissionSeeder.php::permissionCatalog()).
 * The frontend mirror MUST NOT silently diverge — a frontend permission name
 * that differs from the backend code reintroduces the class of defect where a
 * valid administrator is refused (or granted a mismatched) action.
 *
 * This test reads the BACKEND seeder from disk as the single source of truth
 * and verifies the frontend `useAccess.ts` mirror against it. The frontend is
 * presentation-only (backend `authorize:` + RLS stay authoritative —
 * SECURITY.md §27, §33), so the security-relevant directions enforced here
 * are:
 *
 *   A) NO PHANTOM CODES — every frontend permission code is a real backend
 *      code (catches typos, renamed/divergent names, orphaned legacy codes).
 *   B) MIRROR COMPLETENESS — every backend catalog code is defined in the
 *      frontend `PERMISSIONS` map (the vocabulary is one source of truth).
 *   C) ROLE MAPS USE CANONICAL CODES — every code referenced in any frontend
 *      role→permission map is a `PERMISSIONS` member (no inline strings).
 *   D) NO OVER-CLAIM — a frontend role never exposes a permission the backend
 *      role does not actually hold (UI never over-reaches backend authority).
 *
 * Under-visibility (frontend showing FEWER codes than the backend grants) is
 * a UX-readiness gap, not a security hole; it is reported, not failed, so the
 * strict invariant stays about correctness of what IS shown.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

import { PERMISSIONS } from './useAccess';

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Backend seeder = the canonical, authoritative source of truth.
const BACKEND_SEEDER = readFileSync(
  resolve(__dirname, '..', '..', '..', 'backend', 'database', 'seeders', 'RolePermissionSeeder.php'),
  'utf-8',
);
const FRONTEND_SRC = readFileSync(resolve(__dirname, 'useAccess.ts'), 'utf-8');

const CODE = /[a-z]+:[a-z_]+/;

/** Extract the canonical catalog codes from the backend permissionCatalog(). */
function backendCatalogCodes(): Set<string> {
  // Catalog keys are the `'domain:action' => [` lines inside permissionCatalog().
  const catalogBlock = BACKEND_SEEDER.match(/public static function permissionCatalog\(\): array\s*\{([\s\S]*?)\n    \}/);
  const block = catalogBlock?.[1] ?? BACKEND_SEEDER;
  const codes = new Set<string>();
  for (const m of block.matchAll(/^\s*'([a-z_]+:[a-z_\-]+)'\s*=>/gm)) {
    codes.add(m[1]);
  }
  return codes;
}

/** Extract backend role code -> set of granted permission codes (catalog()). */
function backendRoleGrants(): Map<string, Set<string>> {
  const catalogBlock = BACKEND_SEEDER.match(/public static function catalog\(\): array\s*\{([\s\S]*?)\n    \}/)?.[1] ?? '';
  const allCodes = backendCatalogCodes();
  const grants = new Map<string, Set<string>>();

  // Split catalog() into per-role entries. Roles are the top-level arrays at
  // 12-space indentation (e.g. `            'superadmin' => [`).
  const roleMatches = [...catalogBlock.matchAll(/^            '([a-z_]+)'\s*=>\s*\[/gm)];
  for (let i = 0; i < roleMatches.length; i++) {
    const role = roleMatches[i][1];
    const start = roleMatches[i].index!;
    const end = i + 1 < roleMatches.length ? roleMatches[i + 1].index! : catalogBlock.length;
    const body = catalogBlock.slice(start, end);

    // superadmin uses `array_keys(self::permissionCatalog())` = every code.
    if (/array_keys\s*\(\s*self::permissionCatalog\(\)\s*\)/.test(body)) {
      grants.set(role, allCodes);
      continue;
    }

    const perms = new Set<string>();
    for (const m of body.matchAll(/'([a-z_]+:[a-z_\-]+)'/g)) {
      perms.add(m[1]);
    }
    grants.set(role, perms);
  }
  return grants;
}

/** Extract the frontend PERMISSIONS member -> code map. */
function frontendPermissions(): Map<string, string> {
  const map = new Map<string, string>();
  const block = FRONTEND_SRC.match(/export const PERMISSIONS = \{([\s\S]*?)\} as const;/)?.[1] ?? FRONTEND_SRC;
  for (const m of block.matchAll(/^\s{2}([A-Z][A-Z0-9_]+):\s*'([a-z_]+:[a-z_\-]+)',/gm)) {
    map.set(m[1], m[2]);
  }
  return map;
}

/** Extract the frontend [ROLES.X] -> list of codes from rolePermissions. */
function frontendRolePermissions(): Map<string, Set<string>> {
  const map = new Map<string, Set<string>>();
  const block = FRONTEND_SRC.match(/const rolePermissions:[^{]*\{([\s\S]*?)\n    \};/)?.[1] ?? '';
  for (const m of block.matchAll(/^\s+\[ROLES\.([A-Z_]+)\]:\s*\[([\s\S]*?)\],/gm)) {
    const key = m[1];
    const codes = new Set<string>();
    for (const c of m[2].matchAll(/PERMISSIONS\.([A-Z0-9_]+)/g)) {
      codes.add(c[1]);
    }
    if (codes.size > 0) map.set(key, codes);
  }
  return map;
}

const backendCodes = backendCatalogCodes();
const frontendPerms = frontendPermissions();
const backendGrants = backendRoleGrants();
const frontendRoleMaps = frontendRolePermissions();

describe('RBAC permission-code parity (SECURITY.md §6) — backend seeder is the source of truth', () => {
  it('A) every frontend PERMISSIONS code is a real backend catalog code (no phantom / typo / divergent name)', () => {
    const phantom = [...frontendPerms.values()].filter((code) => !backendCodes.has(code));
    expect(phantom, `Frontend PERMISSIONS contains code(s) not in the backend catalog: ${phantom.join(', ')}`).toEqual([]);
  });

  it('B) the backend catalog is exactly mirrored by the frontend PERMISSIONS vocabulary (one source of truth)', () => {
    const missing = [...backendCodes].filter((code) => ![...frontendPerms.values()].includes(code));
    expect(missing, `Backend catalog code(s) missing from frontend PERMISSIONS: ${missing.join(', ')}`).toEqual([]);
  });

  it('C) frontend role→permission maps reference only PERMISSIONS members (no inline/loose strings)', () => {
    const values = new Set(frontendPerms.values());
    const loose: string[] = [];
    const block = FRONTEND_SRC.match(/const rolePermissions: Record<string, PermissionCode\[\]> = \{([\s\S]*?)\n    \};/)?.[1] ?? '';
    for (const m of block.matchAll(/'([a-z_]+:[a-z_\-]+)'/g)) {
      if (!values.has(m[1])) loose.push(m[1]);
    }
    expect(loose, `Inline permission code(s) not defined in PERMISSIONS: ${loose.join(', ')}`).toEqual([]);
  });

  it('D) no frontend role over-claims: each role map is a SUBSET of the backend role grant (UI never exceeds backend authority)', () => {
    const roleCodeByMember: Record<string, string> = {
      SUPERADMIN: 'superadmin',
      SUPPORT_AGENT: 'support_agent',
      ORG_ADMIN: 'org_admin',
      ORG_FINANCE: 'org_finance',
      HOSPITAL_ADMIN: 'hospital_admin',
      BRANCH_MANAGER: 'branch_manager',
      RECEPTIONIST: 'receptionist',
      BILLING_CLERK: 'billing_clerk',
      DOCTOR: 'doctor',
      NURSE: 'nurse',
      PHARMACIST: 'pharmacist',
      LAB_TECHNICIAN: 'lab_technician',
      LAB_SUPERVISOR: 'lab_supervisor',
      RADIOGRAPHER: 'radiographer',
      RADIOLOGIST: 'radiologist',
    };

    const overClaims: string[] = [];
    for (const [roleMember, perms] of frontendRoleMaps) {
      const backendRole = roleCodeByMember[roleMember];
      const backendSet = backendGrants.get(backendRole) ?? new Set<string>();
      for (const member of perms) {
        const resolved = frontendPerms.get(member);
        if (resolved && !backendSet.has(resolved)) {
          overClaims.push(`${backendRole ?? roleMember}:${resolved}`);
        }
      }
    }
    expect(overClaims, `Frontend role(s) expose permission(s) their backend role does not hold: ${overClaims.join(', ')}`).toEqual([]);
  });

  it('superadmin still resolves to the full backend catalog (platform-scope bypass matches backend grant)', () => {
    expect([...backendGrants.get('superadmin')!].length).toBe(backendCodes.size);
  });
});

// UX-readiness (non-failing) diagnostic: how many backend codes each frontend
// role map under-exposes. Under-visibility is a presentation gap, not a
// security boundary — it is reported for the UI-readiness review, never fails.
describe('RBAC under-visibility diagnostic (UX-readiness, non-blocking)', () => {
  const roleCodeByMember: Record<string, string> = {
    SUPERADMIN: 'superadmin', ORG_ADMIN: 'org_admin', ORG_FINANCE: 'org_finance',
    HOSPITAL_ADMIN: 'hospital_admin', BRANCH_MANAGER: 'branch_manager',
    RECEPTIONIST: 'receptionist', BILLING_CLERK: 'billing_clerk', DOCTOR: 'doctor',
    NURSE: 'nurse', PHARMACIST: 'pharmacist', LAB_TECHNICIAN: 'lab_technician',
    LAB_SUPERVISOR: 'lab_supervisor', RADIOGRAPHER: 'radiographer', RADIOLOGIST: 'radiologist',
  };
  it('reports, per role, how many backend-granted permissions the frontend map does not surface', () => {
    const lines: string[] = [];
    for (const [roleMember, perms] of frontendRoleMaps) {
      const backendSet = backendGrants.get(roleCodeByMember[roleMember]);
      if (!backendSet || !perms.size) continue;
      const surfaced = new Set<string>();
      perms.forEach((member) => {
        const code = frontendPerms.get(member);
        if (code) surfaced.add(code);
      });
      const covered = [...backendSet].filter((c) => surfaced.has(c)).length;
      lines.push(`${roleCodeByMember[roleMember]}: surfaces ${covered}/${backendSet.size}`);
    }
    // Informational only — a real parity signal lives in the strict tests above.
    expect(lines.length).toBeGreaterThan(0);
    // eslint-disable-next-line no-console
    console.info(`\n[access-parity] backend-granted coverage per frontend role:\n    ${lines.join('\n    ')}`);
  });
});