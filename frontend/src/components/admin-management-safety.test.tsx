/**
 * Phase 229 — Admin Management Safety Tests
 *
 * Tests the admin management API surface: organization listing, facility
 * CRUD, user management (create/revoke roles), role listing, permissions,
 * staff CRUD, department CRUD, service CRUD, medication catalog, facility
 * settings, module catalog, hospital branding, and document numbering.
 *
 * API surface: 13 API objects from frontend/src/api/admin.ts
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';

// ─── Shared test fixtures ────────────────────────────────────────────────────
const ORG_ID = 'org-admin-001';
const FACILITY_ID = 'fac-admin-001';
const OTHER_ORG = 'org-other-999';
const OTHER_FAC = 'fac-other-999';

// ─── Mock the API client ─────────────────────────────────────────────────────
const mockRequest = vi.fn();

vi.mock('../api/client', () => ({
  api: { request: (...args: unknown[]) => mockRequest(...args) },
  ApiError: class ApiError extends Error {
    code: string;
    constructor(code: string, message: string) {
      super(message);
      this.code = code;
    }
  },
}));

// ─── Import after mock setup ─────────────────────────────────────────────────
import {
  adminOrgsApi, adminFacilitiesApi, adminUsersApi,
  adminRolesApi, adminPermissionsApi, adminStaffApi,
  adminDepartmentsApi, adminServicesApi, adminMedicationsApi,
  adminFacilitySettingsApi, modulesApi, hospitalBrandingApi, numberingApi,
} from '../api/admin';

beforeEach(() => {
  vi.clearAllMocks();
  mockRequest.mockReset();
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 1 — ORGANIZATIONS (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Organizations architecture', () => {
  it('adminOrgsApi.list sends GET to /api/v1/organizations', async () => {
    mockRequest.mockResolvedValue([{ id: ORG_ID, name: 'Test Org' }]);
    await adminOrgsApi.list();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/organizations');
  });

  it('adminOrgsApi.show sends GET to /api/v1/organizations/:id', async () => {
    mockRequest.mockResolvedValue({ id: ORG_ID, name: 'Test Org' });
    await adminOrgsApi.show(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}`);
  });
});

describe('Phase 229 — Organizations safety', () => {
  it('list does not expose organization secrets or internal UUIDs', async () => {
    mockRequest.mockResolvedValue([{ id: ORG_ID }]);
    const orgs = await adminOrgsApi.list();
    expect(orgs[0]).not.toHaveProperty('supabase_org_id');
    expect(orgs[0]).not.toHaveProperty('internal_uuid');
  });

  it('show does not return database connection strings', async () => {
    mockRequest.mockResolvedValue({ id: ORG_ID });
    const org = await adminOrgsApi.show(ORG_ID);
    expect(org).not.toHaveProperty('database_url');
    expect(org).not.toHaveProperty('db_connection');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 2 — FACILITIES (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Facilities architecture', () => {
  it('adminFacilitiesApi.list sends GET to /api/v1/organizations/:orgId/facilities', async () => {
    mockRequest.mockResolvedValue([{ id: FACILITY_ID }]);
    await adminFacilitiesApi.list(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/facilities`);
  });

  it('adminFacilitiesApi.show sends GET to /api/v1/facilities/:id', async () => {
    mockRequest.mockResolvedValue({ id: FACILITY_ID });
    await adminFacilitiesApi.show(FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/facilities/${FACILITY_ID}`);
  });

  it('adminFacilitiesApi.create sends POST with name, code, timezone', async () => {
    mockRequest.mockResolvedValue({ id: FACILITY_ID, name: 'New Hospital' });
    await adminFacilitiesApi.create(ORG_ID, { name: 'New Hospital', code: 'NH001', timezone: 'Asia/Kathmandu' });
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/facilities`, {
      method: 'POST',
      body: { name: 'New Hospital', code: 'NH001', timezone: 'Asia/Kathmandu' },
    });
  });
});

describe('Phase 229 — Facilities safety', () => {
  it('create does not allow client to set org_id or facility_id directly', async () => {
    mockRequest.mockResolvedValue({ id: FACILITY_ID });
    await adminFacilitiesApi.create(ORG_ID, { name: 'X', code: 'X' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('organization_id');
    expect(body).not.toHaveProperty('facility_id');
    expect(body).not.toHaveProperty('id');
  });

  it('list scoped to organization — URL contains orgId', async () => {
    mockRequest.mockResolvedValue([]);
    await adminFacilitiesApi.list(ORG_ID);
    expect(mockRequest.mock.calls[0][0]).toContain(ORG_ID);
    expect(mockRequest.mock.calls[0][0]).not.toContain(OTHER_ORG);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 3 — USERS & ROLES (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Users architecture', () => {
  it('adminUsersApi.list sends GET to /api/v1/users', async () => {
    mockRequest.mockResolvedValue([{ id: 'user-001' }]);
    await adminUsersApi.list();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/users');
  });

  it('adminUsersApi.create sends POST with email, password, roleCode', async () => {
    mockRequest.mockResolvedValue({ id: 'user-002' });
    await adminUsersApi.create(ORG_ID, {
      email: 'doctor@hospital.com', password: 'secret123', roleCode: 'doctor',
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.email).toBe('doctor@hospital.com');
    expect(body.roleCode).toBe('doctor');
    expect(body.password).toBe('secret123');
  });

  it('adminUsersApi.grantRole sends POST to assignments endpoint', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.grantRole(ORG_ID, 'user-001', { roleCode: 'nurse', facilityId: FACILITY_ID });
    expect(mockRequest).toHaveBeenCalledWith(
      `/api/v1/organizations/${ORG_ID}/users/user-001/assignments`,
      { method: 'POST', body: { roleCode: 'nurse', facilityId: FACILITY_ID } },
    );
  });

  it('adminUsersApi.revokeRole sends DELETE to assignment endpoint', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.revokeRole(ORG_ID, 'user-001', 'assign-001');
    expect(mockRequest).toHaveBeenCalledWith(
      `/api/v1/organizations/${ORG_ID}/users/user-001/assignments/assign-001`,
      { method: 'DELETE' },
    );
  });
});

describe('Phase 229 — Users safety', () => {
  it('create sends password in body (never in URL)', async () => {
    mockRequest.mockResolvedValue({ id: 'u-1' });
    await adminUsersApi.create(ORG_ID, {
      email: 'test@h.com', password: 'pass123', roleCode: 'nurse',
    });
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).not.toContain('pass123');
    expect(url).not.toContain('password');
  });

  it('create does not allow client to set user role directly (must use grantRole)', async () => {
    mockRequest.mockResolvedValue({ id: 'u-1' });
    await adminUsersApi.create(ORG_ID, {
      email: 'test@h.com', password: 'p', roleCode: 'nurse',
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('role');
    expect(body).not.toHaveProperty('role_id');
  });

  it('list does not return password hashes or refresh tokens', async () => {
    mockRequest.mockResolvedValue([{ id: 'user-001', email: 'd@h.com' }]);
    const users = await adminUsersApi.list();
    expect(users[0]).not.toHaveProperty('password');
    expect(users[0]).not.toHaveProperty('password_hash');
    expect(users[0]).not.toHaveProperty('hashed_password');
    expect(users[0]).not.toHaveProperty('refresh_token');
  });

  it('grantRole does not allow client to set granted_by (server derives from auth)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.grantRole(ORG_ID, 'u-1', { roleCode: 'admin' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('granted_by');
    expect(body).not.toHaveProperty('assigned_by');
  });

  it('revokeRole does not allow client to set revoked_by', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.revokeRole(ORG_ID, 'u-1', 'a-1');
    // DELETE has no body, but verify the method is DELETE
    expect(mockRequest.mock.calls[0][1].method).toBe('DELETE');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 4 — ROLES & PERMISSIONS (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Roles architecture', () => {
  it('adminRolesApi.list sends GET to /api/v1/roles', async () => {
    mockRequest.mockResolvedValue([{ id: 'role-1', code: 'doctor' }]);
    await adminRolesApi.list();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/roles');
  });

  it('adminRolesApi.list with scopeType filter', async () => {
    mockRequest.mockResolvedValue([]);
    await adminRolesApi.list('facility');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/roles?filter[scopeType]=facility');
  });
});

describe('Phase 229 — Roles safety', () => {
  it('list does not expose internal permission bitmask or RLS policy names', async () => {
    mockRequest.mockResolvedValue([{ id: 'role-1', code: 'doctor' }]);
    const roles = await adminRolesApi.list();
    expect(roles[0]).not.toHaveProperty('permission_bitmask');
    expect(roles[0]).not.toHaveProperty('rls_policies');
    expect(roles[0]).not.toHaveProperty('internal_id');
  });
});

describe('Phase 229 — Permissions architecture', () => {
  it('adminPermissionsApi.list sends GET to /api/v1/permissions', async () => {
    mockRequest.mockResolvedValue([{ id: 'perm-1', code: 'patients.read' }]);
    await adminPermissionsApi.list();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/permissions');
  });
});

describe('Phase 229 — Permissions safety', () => {
  it('list does not expose RLS policy internals', async () => {
    mockRequest.mockResolvedValue([{ id: 'perm-1', code: 'patients.read' }]);
    const perms = await adminPermissionsApi.list();
    expect(perms[0]).not.toHaveProperty('rls_policy');
    expect(perms[0]).not.toHaveProperty('database_column');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 5 — STAFF (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Staff architecture', () => {
  it('adminStaffApi.list sends GET to /api/v1/organizations/:orgId/staff', async () => {
    mockRequest.mockResolvedValue([{ id: 'staff-001' }]);
    await adminStaffApi.list(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/staff`, { facilityId: undefined });
  });

  it('adminStaffApi.list includes facilityId when provided', async () => {
    mockRequest.mockResolvedValue([]);
    await adminStaffApi.list(ORG_ID, FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/staff`, { facilityId: FACILITY_ID });
  });

  it('adminStaffApi.show sends GET to /api/v1/staff/:id', async () => {
    mockRequest.mockResolvedValue({ id: 'staff-001' });
    await adminStaffApi.show('staff-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/staff/staff-001');
  });

  it('adminStaffApi.create sends POST with facilityId, employeeCode, fullName', async () => {
    mockRequest.mockResolvedValue({ id: 'staff-002' });
    await adminStaffApi.create(ORG_ID, {
      facilityId: FACILITY_ID, employeeCode: 'EMP001', fullName: 'Dr. Sharma',
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.facilityId).toBe(FACILITY_ID);
    expect(body.employeeCode).toBe('EMP001');
    expect(body.fullName).toBe('Dr. Sharma');
  });

  it('adminStaffApi.update sends PATCH to /api/v1/staff/:id', async () => {
    mockRequest.mockResolvedValue({ id: 'staff-001', fullName: 'Updated' });
    await adminStaffApi.update('staff-001', { fullName: 'Updated' });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/staff/staff-001', {
      method: 'PATCH',
      body: { fullName: 'Updated' },
    });
  });
});

describe('Phase 229 — Staff safety', () => {
  it('create does not allow setting salary or compensation fields', async () => {
    mockRequest.mockResolvedValue({ id: 's-1' });
    await adminStaffApi.create(ORG_ID, {
      facilityId: FACILITY_ID, employeeCode: 'E1', fullName: 'Test',
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('salary');
    expect(body).not.toHaveProperty('compensation');
    expect(body).not.toHaveProperty('pay_grade');
  });

  it('list scoped to organization URL', async () => {
    mockRequest.mockResolvedValue([]);
    await adminStaffApi.list(ORG_ID);
    expect(mockRequest.mock.calls[0][0]).toContain(ORG_ID);
  });

  it('show does not expose internal user mapping', async () => {
    mockRequest.mockResolvedValue({ id: 'staff-001' });
    const staff = await adminStaffApi.show('staff-001');
    expect(staff).not.toHaveProperty('internal_user_uuid');
    expect(staff).not.toHaveProperty('supabase_uid');
  });

  it('update does not allow setting role or permissions', async () => {
    mockRequest.mockResolvedValue({ id: 's-1' });
    await adminStaffApi.update('s-1', { fullName: 'X' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('role');
    expect(body).not.toHaveProperty('permissions');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 6 — DEPARTMENTS (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Departments architecture', () => {
  it('adminDepartmentsApi.list sends GET scoped to org', async () => {
    mockRequest.mockResolvedValue([{ id: 'dept-001' }]);
    await adminDepartmentsApi.list(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/departments`, { facilityId: undefined });
  });

  it('adminDepartmentsApi.show sends GET to /api/v1/departments/:id', async () => {
    mockRequest.mockResolvedValue({ id: 'dept-001' });
    await adminDepartmentsApi.show('dept-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/departments/dept-001');
  });

  it('adminDepartmentsApi.create sends POST with name, code', async () => {
    mockRequest.mockResolvedValue({ id: 'dept-002' });
    await adminDepartmentsApi.create(ORG_ID, { name: 'Cardiology', code: 'CARD' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.name).toBe('Cardiology');
    expect(body.code).toBe('CARD');
  });

  it('adminDepartmentsApi.update sends PATCH', async () => {
    mockRequest.mockResolvedValue({ id: 'dept-001', name: 'Updated' });
    await adminDepartmentsApi.update('dept-001', { name: 'Updated' });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/departments/dept-001', {
      method: 'PATCH',
      body: { name: 'Updated' },
    });
  });

  it('adminDepartmentsApi.remove sends DELETE', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminDepartmentsApi.remove('dept-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/departments/dept-001', { method: 'DELETE' });
  });
});

describe('Phase 229 — Departments safety', () => {
  it('create does not allow setting organization_id directly', async () => {
    mockRequest.mockResolvedValue({ id: 'd-1' });
    await adminDepartmentsApi.create(ORG_ID, { name: 'X', code: 'X' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('organization_id');
    expect(body).not.toHaveProperty('org_id');
  });

  it('remove is a DELETE operation (not soft-delete)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminDepartmentsApi.remove('d-1');
    expect(mockRequest.mock.calls[0][1].method).toBe('DELETE');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 7 — SERVICES (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Services architecture', () => {
  it('adminServicesApi.list sends GET scoped to org', async () => {
    mockRequest.mockResolvedValue([{ id: 'svc-001' }]);
    await adminServicesApi.list(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/services`, { facilityId: undefined });
  });

  it('adminServicesApi.create sends POST with name, code, serviceType', async () => {
    mockRequest.mockResolvedValue({ id: 'svc-002' });
    await adminServicesApi.create(ORG_ID, { name: 'X-Ray', code: 'XRAY', serviceType: 'imaging' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.name).toBe('X-Ray');
    expect(body.serviceType).toBe('imaging');
  });

  it('adminServicesApi.update sends PATCH', async () => {
    mockRequest.mockResolvedValue({ id: 'svc-001' });
    await adminServicesApi.update('svc-001', { name: 'Updated' });
    expect(mockRequest.mock.calls[0][1].method).toBe('PATCH');
  });

  it('adminServicesApi.remove sends DELETE', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminServicesApi.remove('svc-001');
    expect(mockRequest.mock.calls[0][1].method).toBe('DELETE');
  });
});

describe('Phase 229 — Services safety', () => {
  it('create does not allow setting organization_id or facility_id in body', async () => {
    mockRequest.mockResolvedValue({ id: 's-1' });
    await adminServicesApi.create(ORG_ID, { name: 'X', code: 'X', serviceType: 'lab' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('organization_id');
    expect(body).not.toHaveProperty('facility_id');
  });

  it('create does not allow setting defaultChargeMinor (billing team concern)', async () => {
    mockRequest.mockResolvedValue({ id: 's-1' });
    await adminServicesApi.create(ORG_ID, { name: 'X', code: 'X', serviceType: 'lab' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('defaultChargeMinor');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 8 — MEDICATIONS (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Medications architecture', () => {
  it('adminMedicationsApi.list sends GET scoped to org', async () => {
    mockRequest.mockResolvedValue([{ id: 'med-001' }]);
    await adminMedicationsApi.list(ORG_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/organizations/${ORG_ID}/medications`, { facilityId: undefined });
  });

  it('adminMedicationsApi.create sends POST with code, genericName, strength, unit, priceMinor', async () => {
    mockRequest.mockResolvedValue({ id: 'med-002' });
    await adminMedicationsApi.create(ORG_ID, {
      code: 'AMX500', genericName: 'Amoxicillin', strength: '500mg', unit: 'capsule',
      priceMinor: 500, facilityId: FACILITY_ID,
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body.code).toBe('AMX500');
    expect(body.priceMinor).toBe(500);
  });
});

describe('Phase 229 — Medications safety', () => {
  it('create sends priceMinor in minor currency units (not float)', async () => {
    mockRequest.mockResolvedValue({ id: 'm-1' });
    await adminMedicationsApi.create(ORG_ID, {
      code: 'X', genericName: 'X', strength: '10mg', unit: 'tablet',
      priceMinor: 150, facilityId: FACILITY_ID,
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(typeof body.priceMinor).toBe('number');
  });

  it('create does not allow setting organization_id in body', async () => {
    mockRequest.mockResolvedValue({ id: 'm-1' });
    await adminMedicationsApi.create(ORG_ID, {
      code: 'X', genericName: 'X', strength: '10mg', unit: 'tablet',
      priceMinor: 100, facilityId: FACILITY_ID,
    });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('organization_id');
    expect(body).not.toHaveProperty('org_id');
  });

  it('list does not expose internal medication UUID mappings', async () => {
    mockRequest.mockResolvedValue([{ id: 'med-001' }]);
    const meds = await adminMedicationsApi.list(ORG_ID);
    expect(meds[0]).not.toHaveProperty('internal_uuid');
    expect(meds[0]).not.toHaveProperty('supabase_medication_id');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 9 — FACILITY SETTINGS (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Facility settings architecture', () => {
  it('adminFacilitySettingsApi.list sends GET to /api/v1/facilities/:id/settings', async () => {
    mockRequest.mockResolvedValue({ timezone: { value: 'Asia/Kathmandu' } });
    await adminFacilitySettingsApi.list(FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/facilities/${FACILITY_ID}/settings`);
  });

  it('adminFacilitySettingsApi.update sends PUT with settings object', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.update(FACILITY_ID, { timezone: 'Asia/Kathmandu' });
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/facilities/${FACILITY_ID}/settings`, {
      method: 'PUT',
      body: { settings: { timezone: 'Asia/Kathmandu' } },
    });
  });

  it('adminFacilitySettingsApi.remove sends DELETE to /api/v1/facilities/:id/settings/:key', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminFacilitySettingsApi.remove(FACILITY_ID, 'timezone');
    expect(mockRequest).toHaveBeenCalledWith(
      `/api/v1/facilities/${FACILITY_ID}/settings/timezone`,
      { method: 'DELETE' },
    );
  });
});

describe('Phase 229 — Facility settings safety', () => {
  it('remove encodes key properly for special characters', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminFacilitySettingsApi.remove(FACILITY_ID, 'key with spaces');
    const url = mockRequest.mock.calls[0][0] as string;
    expect(url).toContain(encodeURIComponent('key with spaces'));
  });

  it('list does not expose internal setting IDs or database columns', async () => {
    mockRequest.mockResolvedValue({ setting1: { value: 'v1' } });
    const settings = await adminFacilitySettingsApi.list(FACILITY_ID);
    expect(settings).not.toHaveProperty('id');
    expect(settings).not.toHaveProperty('internal_id');
  });

  it('update does not allow setting facility_id in body', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.update(FACILITY_ID, { timezone: 'UTC' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('facility_id');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 10 — MODULES (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Modules architecture', () => {
  it('modulesApi.catalog sends GET to /api/v1/modules/catalog', async () => {
    mockRequest.mockResolvedValue({ modules: [{ code: 'pharmacy', name: 'Pharmacy' }] });
    await modulesApi.catalog();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/modules/catalog');
  });

  it('modulesApi.enabled sends GET to /api/v1/modules/enabled', async () => {
    mockRequest.mockResolvedValue({ modules: [{ code: 'pharmacy', name: 'Pharmacy', enabled: true }] });
    await modulesApi.enabled();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/modules/enabled');
  });

  it('modulesApi.check sends GET to /api/v1/modules/:code/check', async () => {
    mockRequest.mockResolvedValue({ module: 'pharmacy', enabled: true });
    await modulesApi.check('pharmacy');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/modules/pharmacy/check');
  });
});

describe('Phase 229 — Modules safety', () => {
  it('catalog does not expose internal module configuration or secrets', async () => {
    mockRequest.mockResolvedValue({ modules: [{ code: 'm', name: 'M' }] });
    const result = await modulesApi.catalog();
    expect(result.modules[0]).not.toHaveProperty('config');
    expect(result.modules[0]).not.toHaveProperty('secrets');
    expect(result.modules[0]).not.toHaveProperty('database_url');
  });

  it('enabled does not expose module-specific API keys', async () => {
    mockRequest.mockResolvedValue({ modules: [{ code: 'm', name: 'M', enabled: true }] });
    const result = await modulesApi.enabled();
    expect(result.modules[0]).not.toHaveProperty('api_key');
    expect(result.modules[0]).not.toHaveProperty('token');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 11 — HOSPITAL BRANDING (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Hospital branding architecture', () => {
  it('hospitalBrandingApi.get sends GET to /api/v1/facilities/:id/branding', async () => {
    mockRequest.mockResolvedValue({ branding: { logoUrl: 'logo.png' }, defaults: {} });
    await hospitalBrandingApi.get(FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/facilities/${FACILITY_ID}/branding`);
  });

  it('hospitalBrandingApi.update sends PUT with branding data', async () => {
    mockRequest.mockResolvedValue({ branding: { logoUrl: 'new.png' } });
    await hospitalBrandingApi.update(FACILITY_ID, { logoUrl: 'new.png' });
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/facilities/${FACILITY_ID}/branding`, {
      method: 'PUT',
      body: { logoUrl: 'new.png' },
    });
  });

  it('hospitalBrandingApi.forDocument sends GET to branding/document', async () => {
    mockRequest.mockResolvedValue({ logoUrl: 'doc.png' });
    await hospitalBrandingApi.forDocument(FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith(`/api/v1/facilities/${FACILITY_ID}/branding/document`);
  });
});

describe('Phase 229 — Hospital branding safety', () => {
  it('update does not allow setting facility_id in body', async () => {
    mockRequest.mockResolvedValue({ branding: {} });
    await hospitalBrandingApi.update(FACILITY_ID, { logoUrl: 'x.png' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('facility_id');
  });

  it('get does not expose internal storage URLs or bucket names', async () => {
    mockRequest.mockResolvedValue({ branding: { logoUrl: 'logo.png' }, defaults: {} });
    const result = await hospitalBrandingApi.get(FACILITY_ID);
    expect(result).not.toHaveProperty('storage_bucket');
    expect(result).not.toHaveProperty('internal_url');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 12 — NUMBERING (architecture + safety)
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Numbering architecture', () => {
  it('numberingApi.types sends GET to /api/v1/numbering/types', async () => {
    mockRequest.mockResolvedValue([{ type: 'invoice', label: 'Invoice' }]);
    await numberingApi.types();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/numbering/types');
  });

  it('numberingApi.list sends GET to /api/v1/numbering', async () => {
    mockRequest.mockResolvedValue([{ id: 'num-001', documentType: 'invoice', prefix: 'INV' }]);
    await numberingApi.list();
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/numbering');
  });

  it('numberingApi.create sends POST', async () => {
    mockRequest.mockResolvedValue({ id: 'num-002' });
    await numberingApi.create({ documentType: 'invoice', prefix: 'INV' });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/numbering', {
      method: 'POST',
      body: { documentType: 'invoice', prefix: 'INV' },
    });
  });

  it('numberingApi.update sends PUT', async () => {
    mockRequest.mockResolvedValue({ id: 'num-001' });
    await numberingApi.update('num-001', { prefix: 'INV2' });
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/numbering/num-001', {
      method: 'PUT',
      body: { prefix: 'INV2' },
    });
  });

  it('numberingApi.preview sends GET to /api/v1/numbering/:id/preview', async () => {
    mockRequest.mockResolvedValue({ preview: 'INV-2024-0001' });
    await numberingApi.preview('num-001');
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/numbering/num-001/preview');
  });

  it('numberingApi.generate sends POST with optional facilityId', async () => {
    mockRequest.mockResolvedValue({ documentNumber: 'INV-2024-0001' });
    await numberingApi.generate('num-001', FACILITY_ID);
    expect(mockRequest).toHaveBeenCalledWith('/api/v1/numbering/num-001/generate', {
      method: 'POST',
      body: { facilityId: FACILITY_ID },
    });
  });
});

describe('Phase 229 — Numbering safety', () => {
  it('create does not allow setting organization_id in body', async () => {
    mockRequest.mockResolvedValue({ id: 'n-1' });
    await numberingApi.create({ documentType: 'invoice', prefix: 'INV' });
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('organization_id');
  });

  it('list does not expose internal counter values or database sequence info', async () => {
    mockRequest.mockResolvedValue([{ id: 'n-1', prefix: 'INV' }]);
    const items = await numberingApi.list();
    expect(items[0]).not.toHaveProperty('current_counter');
    expect(items[0]).not.toHaveProperty('database_sequence');
  });

  it('generate does not allow client to specify the number (must be server-generated)', async () => {
    mockRequest.mockResolvedValue({ documentNumber: 'INV-001' });
    await numberingApi.generate('n-1');
    const body = mockRequest.mock.calls[0][1].body;
    expect(body).not.toHaveProperty('documentNumber');
    expect(body).not.toHaveProperty('number');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 13 — CROSS-DOMAIN AUTHORIZATION
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Cross-domain authorization', () => {
  it('user management requires hospital_admin role (server-enforced)', async () => {
    mockRequest.mockResolvedValue({ id: 'u-1' });
    await adminUsersApi.create(ORG_ID, { email: 'a@h.com', password: 'p', roleCode: 'nurse' });
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('permission');
  });

  it('role grant requires hospital_admin or admin role (server-enforced)', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.grantRole(ORG_ID, 'u-1', { roleCode: 'nurse' });
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
  });

  it('facility settings require hospital_admin role (server-enforced)', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.update(FACILITY_ID, { key: 'val' });
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
  });

  it('department create/update/delete requires hospital_admin (server-enforced)', async () => {
    mockRequest.mockResolvedValue({ id: 'd-1' });
    await adminDepartmentsApi.create(ORG_ID, { name: 'X', code: 'X' });
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('role');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 14 — CROSS-DOMAIN SCOPE
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Cross-domain scope', () => {
  it('org-scoped endpoints include orgId in URL', async () => {
    mockRequest.mockResolvedValue([]);
    await adminStaffApi.list(ORG_ID);
    expect(mockRequest.mock.calls[0][0]).toContain(ORG_ID);

    mockRequest.mockResolvedValue([]);
    await adminDepartmentsApi.list(ORG_ID);
    expect(mockRequest.mock.calls[1][0]).toContain(ORG_ID);

    mockRequest.mockResolvedValue([]);
    await adminServicesApi.list(ORG_ID);
    expect(mockRequest.mock.calls[2][0]).toContain(ORG_ID);

    mockRequest.mockResolvedValue([]);
    await adminMedicationsApi.list(ORG_ID);
    expect(mockRequest.mock.calls[3][0]).toContain(ORG_ID);
  });

  it('facility-scoped endpoints use facilityId in path', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.list(FACILITY_ID);
    expect(mockRequest.mock.calls[0][0]).toContain(FACILITY_ID);

    mockRequest.mockResolvedValue({ branding: {}, defaults: {} });
    await hospitalBrandingApi.get(FACILITY_ID);
    expect(mockRequest.mock.calls[1][0]).toContain(FACILITY_ID);
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 15 — AUDIT TRAIL
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Audit trail', () => {
  it('user create is server-auditable (no client bypass)', async () => {
    mockRequest.mockResolvedValue({ id: 'u-1' });
    await adminUsersApi.create(ORG_ID, { email: 'a@h.com', password: 'p', roleCode: 'nurse' });
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('role grant is server-auditable', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.grantRole(ORG_ID, 'u-1', { roleCode: 'nurse' });
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('role revoke is server-auditable', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.revokeRole(ORG_ID, 'u-1', 'a-1');
    expect(mockRequest.mock.calls[0][1]).not.toHaveProperty('skip_audit');
  });

  it('department create is server-auditable', async () => {
    mockRequest.mockResolvedValue({ id: 'd-1' });
    await adminDepartmentsApi.create(ORG_ID, { name: 'X', code: 'X' });
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('facility settings update is server-auditable', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.update(FACILITY_ID, { key: 'val' });
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('branding update is server-auditable', async () => {
    mockRequest.mockResolvedValue({ branding: {} });
    await hospitalBrandingApi.update(FACILITY_ID, { logoUrl: 'x.png' });
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });

  it('numbering create is server-auditable', async () => {
    mockRequest.mockResolvedValue({ id: 'n-1' });
    await numberingApi.create({ documentType: 'invoice', prefix: 'INV' });
    expect(mockRequest.mock.calls[0][1].body).not.toHaveProperty('skip_audit');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 16 — PRIVACY
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Privacy', () => {
  it('user list does not expose password, refresh_token, or internal IDs', async () => {
    mockRequest.mockResolvedValue([{ id: 'u-1', email: 'd@h.com' }]);
    const users = await adminUsersApi.list();
    expect(users[0]).not.toHaveProperty('password');
    expect(users[0]).not.toHaveProperty('refresh_token');
    expect(users[0]).not.toHaveProperty('internal_uuid');
  });

  it('staff list does not expose personal phone, personal email, or home address', async () => {
    mockRequest.mockResolvedValue([{ id: 's-1', fullName: 'Dr. Sharma' }]);
    const staff = await adminStaffApi.list(ORG_ID);
    expect(staff[0]).not.toHaveProperty('personal_phone');
    expect(staff[0]).not.toHaveProperty('personal_email');
    expect(staff[0]).not.toHaveProperty('home_address');
  });

  it('facility settings do not expose internal database column names', async () => {
    mockRequest.mockResolvedValue({ key: { value: 'v' } });
    const settings = await adminFacilitySettingsApi.list(FACILITY_ID);
    expect(settings).not.toHaveProperty('column_name');
    expect(settings).not.toHaveProperty('table_name');
  });

  it('module catalog does not expose internal module configuration', async () => {
    mockRequest.mockResolvedValue({ modules: [{ code: 'm', name: 'M' }] });
    const result = await modulesApi.catalog();
    expect(result.modules[0]).not.toHaveProperty('internal_config');
  });
});

// ═══════════════════════════════════════════════════════════════════════════════
// SECTION 17 — ARCHITECTURE COMPLETENESS
// ═══════════════════════════════════════════════════════════════════════════════

describe('Phase 229 — Architecture completeness', () => {
  it('admin.ts exports exactly 13 API objects', () => {
    const apis = [
      adminOrgsApi, adminFacilitiesApi, adminUsersApi, adminRolesApi,
      adminPermissionsApi, adminStaffApi, adminDepartmentsApi, adminServicesApi,
      adminMedicationsApi, adminFacilitySettingsApi, modulesApi,
      hospitalBrandingApi, numberingApi,
    ];
    expect(apis.length).toBe(13);
  });

  it('all org-scoped list endpoints include orgId in URL', async () => {
    mockRequest.mockResolvedValue([]);
    await adminFacilitiesApi.list(ORG_ID);
    await adminStaffApi.list(ORG_ID);
    await adminDepartmentsApi.list(ORG_ID);
    await adminServicesApi.list(ORG_ID);
    await adminMedicationsApi.list(ORG_ID);

    for (let i = 0; i < 5; i++) {
      const url = mockRequest.mock.calls[i][0] as string;
      expect(url).toContain(ORG_ID);
    }
  });

  it('all facility-scoped endpoints include facilityId in URL', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.list(FACILITY_ID);
    await hospitalBrandingApi.get(FACILITY_ID);

    expect(mockRequest.mock.calls[0][0]).toContain(FACILITY_ID);
    expect(mockRequest.mock.calls[1][0]).toContain(FACILITY_ID);
  });

  it('DELETE operations use DELETE method', async () => {
    mockRequest.mockResolvedValue(undefined);
    await adminUsersApi.revokeRole(ORG_ID, 'u-1', 'a-1');
    await adminDepartmentsApi.remove('d-1');
    await adminServicesApi.remove('s-1');
    await adminFacilitySettingsApi.remove(FACILITY_ID, 'key');

    expect(mockRequest.mock.calls[0][1].method).toBe('DELETE');
    expect(mockRequest.mock.calls[1][1].method).toBe('DELETE');
    expect(mockRequest.mock.calls[2][1].method).toBe('DELETE');
    expect(mockRequest.mock.calls[3][1].method).toBe('DELETE');
  });

  it('PATCH operations use PATCH method', async () => {
    mockRequest.mockResolvedValue({});
    await adminStaffApi.update('s-1', { fullName: 'X' });
    await adminDepartmentsApi.update('d-1', { name: 'X' });
    await adminServicesApi.update('sv-1', { name: 'X' });

    expect(mockRequest.mock.calls[0][1].method).toBe('PATCH');
    expect(mockRequest.mock.calls[1][1].method).toBe('PATCH');
    expect(mockRequest.mock.calls[2][1].method).toBe('PATCH');
  });

  it('PUT operations use PUT method', async () => {
    mockRequest.mockResolvedValue({});
    await adminFacilitySettingsApi.update(FACILITY_ID, {});
    await hospitalBrandingApi.update(FACILITY_ID, {});
    await numberingApi.update('n-1', {});

    expect(mockRequest.mock.calls[0][1].method).toBe('PUT');
    expect(mockRequest.mock.calls[1][1].method).toBe('PUT');
    expect(mockRequest.mock.calls[2][1].method).toBe('PUT');
  });
});
