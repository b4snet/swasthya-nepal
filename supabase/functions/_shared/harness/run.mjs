/**
 * Local Edge Function harness — executes the pure `_shared` modules with the
 * Node runtime (Deno-compatible: WebCrypto + standard globals, zero deps).
 *
 * Run:  node supabase/functions/_shared/harness/run.mjs
 *
 * What this proves (CHECKPOINT 6, pure-logic tier):
 *   - JWT verification: missing/malformed/bad-signature/expired/wrong-audience
 *   - identity: subject → application user (users.auth_subject_id contract)
 *   - context: platform / support / tenant decisions, facility & branch
 *     proposal validation, suspension
 *   - claims: exactly the five app_* keys, derived only from the context
 *   - authorization: platform vs tenant scoping, support read-only
 *   - contract: envelope shapes, correlation ids, no secrets in responses
 *
 * The DB-coupled half (subject rows, role_assignments, RLS isolation) is
 * proven against real PostgreSQL by backend/tests/Feature/
 * EdgeFunctionPipelineTest.php. See supabase/README.md for the validation
 * tiers.
 */
import assert from 'node:assert/strict';

import { verifyJwt, signJwt } from '../jwt.ts';
import { JwtError } from '../errors.ts';
import { CLAIM_KEYS, claimsComplete, claimsFromContext, normalizeClaims } from '../claims.ts';
import { resolveContext } from '../context.ts';
import { can } from '../authorize.ts';
import { correlationId } from '../envelope.ts';
import { resolveAppUser } from '../identity.ts';
import { handleHealthAuth } from '../health_auth.ts';
import { handleMe } from '../me.ts';
import { handlePatientsList } from '../patients_list.ts';
import { handlePatientsShow } from '../patients_show.ts';
import { handleAppointmentsCreate } from '../appointments_create.ts';
import { handleAppointmentsCheckin } from '../appointments_checkin.ts';
import { handleEncountersCreate } from '../encounters_create.ts';
import { handleEncounterNotesDraft } from '../encounter_notes_draft.ts';
import { handleEncounterNotesSign } from '../encounter_notes_sign.ts';
import { handleEncountersSign } from '../encounters_sign.ts';
import { handleEncountersInvoice } from '../encounters_invoice.ts';
import { handleInvoicesPay } from '../invoices_pay.ts';
import { handleInvoicesShow } from '../invoices_show.ts';
import { handleInvoicesPayments } from '../invoices_payments.ts';
import { handleEncountersCharges } from '../encounters_charges.ts';
import { handleEncountersShow } from '../encounters_show.ts';
import { handleEncountersNotes } from '../encounter_notes_list.ts';
import { handleAppointmentsShow } from '../appointments_show.ts';
import { handleAppointmentsIndex } from '../appointments_index.ts';
import { handlePatientsSearch } from '../patients_search.ts';
import { handlePatientsTimeline } from '../patients_timeline.ts';
import { handlePatientsIdentifiers } from '../patients_identifiers.ts';
import { handlePatientsContacts } from '../patients_contacts.ts';
import { handlePatientsInsurancePolicies } from '../patients_insurance_policies.ts';
import { handlePatientsConsents } from '../patients_consents.ts';
import { handlePatientsDocuments } from '../patients_documents.ts';
import { handleAppointmentsQueue } from '../appointments_queue.ts';
import { handleOrganizationsDepartments } from '../organizations_departments.ts';
import { handleFacilitiesBranches } from '../facilities_branches.ts';
import { handleOrganizationsLocations } from '../organizations_locations.ts';
import { handleOrganizationsWards } from '../organizations_wards.ts';
import { handleOrganizationsRooms } from '../organizations_rooms.ts';
import { handleOrganizationsBeds } from '../organizations_beds.ts';
import { handleOrganizationsStaff } from '../organizations_staff.ts';
import { handleOrganizationsServices } from '../organizations_services.ts';
import { handleOrganizationsPayers } from '../organizations_payers.ts';
import { handleOrganizationsMedications } from '../organizations_medications.ts';
import { handleOrganizationsScheduleTemplates } from '../organizations_schedule_templates.ts';
import { handleOrganizationsScheduleExceptions } from '../organizations_schedule_exceptions.ts';
import { handleFacilitiesSettings } from '../facilities_settings.ts';
import { EdgeError, ErrorCodes } from '../errors.ts';

/* ------------------------------------------------------------------ */
/* Micro test runner                                                   */
/* ------------------------------------------------------------------ */

let passed = 0;
let failed = 0;
const failures = [];

async function test(name, fn) {
  try {
    await fn();
    passed += 1;
    console.log(`  \u2713 ${name}`);
  } catch (error) {
    failed += 1;
    failures.push({ name, error });
    console.log(`  \u2717 ${name}`);
    console.log(`      ${error?.message ?? error}`);
  }
}

/* ------------------------------------------------------------------ */
/* Fixtures (server-side shapes only — never client input)             */
/* ------------------------------------------------------------------ */

const SECRET = 'harness-jwt-secret-0123456789abcdef0123456789abcdef';
const ISSUER = 'supabase';
const AUDIENCE = 'authenticated';
const NOW = Math.floor(Date.now() / 1000);

/*
 * Realistic GoTrue identity fixtures (CHECKPOINT 7): `sub` is the UUID of
 * auth.users.id — NOT the application user id. The mapping is server-side
 * only (auth.users → users.auth_subject_id). These stand in for actual
 * GoTrue rows; everything else about them (iss/aud/exp/nbf/iat/jti, claim
 * placement) matches the GoTrue access-token contract.
 */
const SUB_TENANT_ADMIN = 'aaaaaaaa-0000-4000-8000-000000000001';
const SUB_FAC_DOCTOR = 'aaaaaaaa-0000-4000-8000-000000000002';
const SUB_PLATFORM = 'aaaaaaaa-0000-4000-8000-000000000003';
const SUB_LOCKED = 'aaaaaaaa-0000-4000-8000-000000000004';
const SUB_DISABLED = 'aaaaaaaa-0000-4000-8000-000000000005';
const SUB_SUSPENDED = 'aaaaaaaa-0000-4000-8000-000000000006';
const SUB_UNKNOWN = 'bbbbbbbb-0000-4000-8000-000000000001';

const orgA = { id: 'org-a', status: 'active', timezone: 'Asia/Kathmandu' };
const orgB = { id: 'org-b', status: 'active' };
const orgSuspended = { id: 'org-suspended', status: 'disabled' };

const facA1 = { id: 'fac-a1', tenantId: 'org-a', timezone: 'Asia/Kathmandu' };
const facA2 = { id: 'fac-a2', tenantId: 'org-a' };
const facB = { id: 'fac-b', tenantId: 'org-b' };

const branchA1 = { id: 'br-a1', tenantId: 'org-a', facilityId: 'fac-a1' };
// br-a2 belongs to a DIFFERENT facility of the same tenant — a valid
// proposal under fac-a2 context, an escape attempt under fac-a1 context.
const branchA2 = { id: 'br-a2', tenantId: 'org-a', facilityId: 'fac-a2' };
const branchB = { id: 'br-b', tenantId: 'org-b', facilityId: 'fac-b' };

const permissions = {
  doctor: [
    { code: 'patient:view', scope: 'tenant' },
    // encounter:create — the doctor starts the visit from a checked-in
    // appointment (RolePermissionSeeder doctor role parity).
    { code: 'encounter:create', scope: 'tenant' },
    { code: 'encounter:document', scope: 'tenant' },
    { code: 'encounter:sign', scope: 'tenant' },
    { code: 'billing:view', scope: 'tenant' },
    // medication:view — the seeded doctor role ALSO holds the formulary
    // read gate (RolePermissionSeeder doctor block line 331) — the Phase
    // 42 facility-scoped success actor.
    { code: 'medication:view', scope: 'tenant' },
    // schedule:view — the seeded doctor role ALSO holds the schedule read
    // gate (RolePermissionSeeder doctor block line 328) — the Phase 43
    // facility-scoped success actor.
    { code: 'schedule:view', scope: 'tenant' },
  ],
  superadmin: [
    { code: 'organization:manage', scope: 'both' },
    { code: 'support:manage', scope: 'platform' },
    { code: 'platform:admin', scope: 'platform' },
  ],
  // Org-level catalog administration (mirror of the seeded org_admin role,
  // RolePermissionSeeder — org_admin holds department/branch/location/ward:
  // view + manage — the Phase 33/34/35/36 catalog read gates).
  orgAdmin: [
    { code: 'organization:manage', scope: 'both' },
    { code: 'patient:view', scope: 'tenant' },
    { code: 'department:view', scope: 'tenant' },
    { code: 'department:manage', scope: 'tenant' },
    { code: 'branch:view', scope: 'tenant' },
    { code: 'branch:manage', scope: 'tenant' },
    { code: 'location:view', scope: 'tenant' },
    { code: 'location:manage', scope: 'tenant' },
    { code: 'ward:view', scope: 'tenant' },
    { code: 'ward:manage', scope: 'tenant' },
    { code: 'room:view', scope: 'tenant' },
    { code: 'room:manage', scope: 'tenant' },
    { code: 'bed:view', scope: 'tenant' },
    { code: 'bed:manage', scope: 'tenant' },
    { code: 'staff:view', scope: 'tenant' },
    { code: 'staff:manage', scope: 'tenant' },
    { code: 'service:view', scope: 'tenant' },
    { code: 'service:manage', scope: 'tenant' },
    { code: 'payer:view', scope: 'tenant' },
    { code: 'payer:manage', scope: 'tenant' },
    { code: 'medication:view', scope: 'tenant' },
    { code: 'medication:manage', scope: 'tenant' },
    { code: 'schedule:view', scope: 'tenant' },
    { code: 'schedule:manage', scope: 'tenant' },
    // settings:view + settings:manage — the seeded org_admin role ALSO
    // holds the facility-configuration read gate (RolePermissionSeeder
    // org_admin block line 204) — the Phase 45 org-level success actor.
    { code: 'settings:view', scope: 'tenant' },
    { code: 'settings:manage', scope: 'tenant' },
  ],
  // Facility-scoped catalog administration (mirror of the seeded
  // hospital_admin role, RolePermissionSeeder — hospital_admin holds
  // department/branch/location/ward: view + manage at exactly one
  // facility).
  hospitalAdmin: [
    { code: 'department:view', scope: 'tenant' },
    { code: 'department:manage', scope: 'tenant' },
    { code: 'branch:view', scope: 'tenant' },
    { code: 'branch:manage', scope: 'tenant' },
    { code: 'location:view', scope: 'tenant' },
    { code: 'location:manage', scope: 'tenant' },
    { code: 'ward:view', scope: 'tenant' },
    { code: 'ward:manage', scope: 'tenant' },
    { code: 'room:view', scope: 'tenant' },
    { code: 'room:manage', scope: 'tenant' },
    { code: 'bed:view', scope: 'tenant' },
    { code: 'bed:manage', scope: 'tenant' },
    { code: 'staff:view', scope: 'tenant' },
    { code: 'staff:manage', scope: 'tenant' },
    { code: 'service:view', scope: 'tenant' },
    { code: 'service:manage', scope: 'tenant' },
    { code: 'payer:view', scope: 'tenant' },
    { code: 'payer:manage', scope: 'tenant' },
    { code: 'medication:view', scope: 'tenant' },
    { code: 'medication:manage', scope: 'tenant' },
    { code: 'schedule:view', scope: 'tenant' },
    { code: 'schedule:manage', scope: 'tenant' },
    // settings:view + settings:manage — the seeded hospital_admin role
    // ALSO holds the facility-configuration read gate (RolePermissionSeeder
    // hospital_admin block line 252) — the Phase 45 facility-scoped
    // success actor.
    { code: 'settings:view', scope: 'tenant' },
    { code: 'settings:manage', scope: 'tenant' },
  ],
  // Front-desk booking actor (mirror of the seeded receptionist role,
  // RolePermissionSeeder — appointment:book is facility-scoped; the seeded
  // receptionist ALSO holds consent:view + consent:manage — the Phase 31
  // consent read gate).
  receptionist: [
    { code: 'patient:view', scope: 'tenant' },
    { code: 'patient:register', scope: 'tenant' },
    { code: 'consent:view', scope: 'tenant' },
    { code: 'document:view', scope: 'tenant' },
    { code: 'schedule:view', scope: 'tenant' },
    { code: 'appointment:view', scope: 'tenant' },
    { code: 'appointment:book', scope: 'tenant' },
    { code: 'appointment:checkin', scope: 'tenant' },
  ],
  // The billing surface (mirror of the seeded billing_clerk role,
  // RolePermissionSeeder — billing:invoice is facility-scoped; the seeded
  // billing_clerk ALSO holds insurance:view + payer:view — the Phase 30
  // insurance read gate).
  billingClerk: [
    { code: 'patient:view', scope: 'tenant' },
    { code: 'patient:search', scope: 'tenant' },
    { code: 'insurance:view', scope: 'tenant' },
    { code: 'appointment:view', scope: 'tenant' },
    { code: 'queue:view', scope: 'tenant' },
    { code: 'encounter:view', scope: 'tenant' },
    { code: 'billing:view', scope: 'tenant' },
    { code: 'billing:invoice', scope: 'tenant' },
    { code: 'billing:collect', scope: 'tenant' },
    // payer:view — the seeded billing_clerk ALSO holds the payer read gate
    // (RolePermissionSeeder line 314) — the Phase 41 payer success actor.
    { code: 'payer:view', scope: 'tenant' },
  ],
};

const users = {
  tenantAdmin: { id: 'u-tenant-admin', email: 'admin@a.test', status: 'active' },
  facilityDoctor: { id: 'u-fac-doctor', email: 'doc@a.test', status: 'active' },
  noPermissionUser: { id: 'u-noperm', email: 'noperm@a.test', status: 'active' },
  platformAgent: { id: 'u-platform', email: 'platform@swasthya.test', status: 'active' },
  lockedUser: { id: 'u-locked', email: 'locked@a.test', status: 'locked' },
  disabledUser: { id: 'u-disabled', email: 'disabled@a.test', status: 'disabled' },
  receptionist: { id: 'u-receptionist', email: 'front@a.test', status: 'active' },
  noAssignmentUser: { id: 'u-no-assignment', email: 'isolated@a.test', status: 'active' },
  // A second fac-a1 doctor (STAFF_A1_EXCEPTION): HAS encounter:document but
  // is NOT the encounter provider — the clinical-author rule must deny.
  doctorB: { id: 'u-doctor-b', email: 'docb@a.test', status: 'active' },
  // Billing actor (facility-scoped billing clerk at fac-a1).
  cashier: { id: 'u-cashier', email: 'cash@a.test', status: 'active' },
  // Facility-scoped catalog administrator at fac-a1 (mirror of the seeded
  // hospital_admin role — the Phase 33 facility-scope department actor).
  hospitalAdmin: { id: 'u-hosp-admin', email: 'hosp@a.test', status: 'active' },
};

const assignments = {
  'u-tenant-admin': [{
    id: 'as-1', userId: 'u-tenant-admin', roleId: 'r-org-admin', tenantId: 'org-a', facilityId: null, branchId: null,
    scopeType: 'organization',
    role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
  }],
  'u-fac-doctor': [{
    id: 'as-2', userId: 'u-fac-doctor', roleId: 'r-doctor', tenantId: 'org-a', facilityId: 'fac-a1', branchId: null,
    scopeType: 'facility',
    role: { id: 'r-doctor', code: 'doctor', scopeType: 'facility', permissions: permissions.doctor },
  }],
  'u-noperm': [{
    id: 'as-2b', userId: 'u-noperm', roleId: 'r-receptionist', tenantId: 'org-a', facilityId: 'fac-a1', branchId: null,
    scopeType: 'facility',
    // No patient:view — the authorization gate must deny 403 SCOPE_DENIED.
    role: { id: 'r-receptionist', code: 'receptionist', scopeType: 'facility', permissions: [] },
  }],
  'u-platform': [{
    id: 'as-3', userId: 'u-platform', roleId: 'r-superadmin', tenantId: null, facilityId: null, branchId: null,
    scopeType: 'platform',
    role: { id: 'r-superadmin', code: 'superadmin', scopeType: 'platform', permissions: permissions.superadmin },
  }],
  'u-locked': [{
    id: 'as-4', userId: 'u-locked', roleId: 'r-org-admin', tenantId: 'org-a', facilityId: null, branchId: null,
    scopeType: 'organization',
    role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
  }],
  // The booking actor: facility-scoped receptionist at fac-a1.
  'u-receptionist': [{
    id: 'as-5', userId: 'u-receptionist', roleId: 'r-receptionist', tenantId: 'org-a', facilityId: 'fac-a1', branchId: null,
    scopeType: 'facility',
    role: { id: 'r-receptionist', code: 'receptionist', scopeType: 'facility', permissions: permissions.receptionist },
  }],
  // No active assignments at all → no context → fail closed.
  'u-doctor-b': [{
    id: 'as-6', userId: 'u-doctor-b', roleId: 'r-doctor', tenantId: 'org-a', facilityId: 'fac-a1', branchId: null,
    scopeType: 'facility',
    role: { id: 'r-doctor', code: 'doctor', scopeType: 'facility', permissions: permissions.doctor },
  }],
  // The billing actor: facility-scoped billing clerk at fac-a1.
  'u-cashier': [{
    id: 'as-7', userId: 'u-cashier', roleId: 'r-billing-clerk', tenantId: 'org-a', facilityId: 'fac-a1', branchId: null,
    scopeType: 'facility',
    role: { id: 'r-billing-clerk', code: 'billing_clerk', scopeType: 'facility', permissions: permissions.billingClerk },
  }],
  // The facility-scoped catalog actor: hospital_admin at fac-a1 (the exact
  // RolePermissionSeeder hospital_admin mirror — department:view +
  // department:manage; branchId null — branch context comes only from the
  // validated X-Swasthya-Branch proposal, never the assignment).
  'u-hosp-admin': [{
    id: 'as-8', userId: 'u-hosp-admin', roleId: 'r-hosp-admin', tenantId: 'org-a', facilityId: 'fac-a1', branchId: null,
    scopeType: 'facility',
    role: { id: 'r-hosp-admin', code: 'hospital_admin', scopeType: 'facility', permissions: permissions.hospitalAdmin },
  }],
  'u-no-assignment': [],
};

// auth.users → application users mapping (users.auth_subject_id = sub).
// The subject is the GoTrue UUID; the AppUser id is the application id.
const SUB_NO_PERM = 'aaaaaaaa-0000-4000-8000-000000000007';
const SUB_RECEPTIONIST = 'aaaaaaaa-0000-4000-8000-000000000008';
const SUB_NO_ASSIGNMENT = 'aaaaaaaa-0000-4000-8000-000000000009';
const SUB_DOCTOR_B = 'aaaaaaaa-0000-4000-8000-00000000000a';
const SUB_CASHIER = 'aaaaaaaa-0000-4000-8000-00000000000b';
const SUB_HOSP_ADMIN = 'aaaaaaaa-0000-4000-8000-00000000000c';

const bySubject = new Map([
  [SUB_TENANT_ADMIN, users.tenantAdmin],
  [SUB_FAC_DOCTOR, users.facilityDoctor],
  [SUB_NO_PERM, users.noPermissionUser],
  [SUB_PLATFORM, users.platformAgent],
  [SUB_LOCKED, users.lockedUser],
  [SUB_DISABLED, users.disabledUser],
  [SUB_RECEPTIONIST, users.receptionist],
  [SUB_NO_ASSIGNMENT, users.noAssignmentUser],
  [SUB_DOCTOR_B, users.doctorB],
  [SUB_CASHIER, users.cashier],
  [SUB_HOSP_ADMIN, users.hospitalAdmin],
]);

const sessions = new Map([
  // platform agent has an active support session into org-a / fac-a1
  ['u-platform', { id: 'ss-1', organizationId: 'org-a', facilityId: 'fac-a1' }],
]);

// Server-side provider (staff) rows. Visibility is simulated by filtering on
// the authoritative claims — the REAL filter is RLS (proven by the PHP tier).
const staff = [
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000401', tenantId: 'org-a', facilityId: 'fac-a1', fullName: 'Dr. Kiran Adhikari',
    userId: 'u-fac-doctor',
  },
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000402', tenantId: 'org-a', facilityId: 'fac-a1', fullName: 'Dr. Bipin Joshi',
    userId: 'u-doctor-b',
  },
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000501', tenantId: 'org-a', facilityId: 'fac-a2', fullName: 'Dr. Maya Rai',
  },
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000601', tenantId: 'org-b', facilityId: 'fac-b', fullName: 'Dr. Nirav Shah',
  },
];

// Provider schedules (mirror of SlotService inputs): template window + grid,
// plus live holdings. A provider with no entry for a date has no availability.
const SLOT_DATE = '2026-03-02'; // a Monday
const schedules = {
  // fac-a1 provider: 09:00–17:00, 30-minute slots, capacity 1.
  ['aaaaaaaa-0000-4000-8000-000000000401|' + SLOT_DATE]: {
    exceptionActive: false,
    templates: [{ startsAt: '09:00:00', endsAt: '17:00:00', slotMinutes: 30, capacity: 1 }],
    holdings: [],
  },
  // Same grid, but with a leave exception that day → no slots at all.
  ['aaaaaaaa-0000-4000-8000-000000000402|' + SLOT_DATE]: {
    exceptionActive: true,
    templates: [{ startsAt: '09:00:00', endsAt: '17:00:00', slotMinutes: 30, capacity: 1 }],
    holdings: [],
  },
};

// Server-side patient rows (mirror of the PatientRow contract). Visibility
// is simulated by filtering on the authoritative claims — the REAL filter is
// the p_rls_patients_* policies proven by the PHP DB tier.
const patients = [
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000101', tenantId: 'org-a', facilityId: 'fac-a1', mrn: 'MRN-A1-001', fullName: 'Aarav Shrestha',
    dateOfBirth: '1990-01-01', sex: 'male', bloodGroup: 'O+', status: 'active',
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
  },
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000102', tenantId: 'org-a', facilityId: 'fac-a1', mrn: 'MRN-A1-002', fullName: 'Bimala Gurung',
    dateOfBirth: '1985-06-15', sex: 'female', bloodGroup: 'A+', status: 'active',
    createdAt: '2026-01-02T00:00:00Z', updatedAt: null,
  },
  {
    // Optional fields all null — the presentation contract must tolerate it.
    id: 'aaaaaaaa-0000-4000-8000-000000000103', tenantId: 'org-a', facilityId: 'fac-a1', mrn: 'MRN-A1-000', fullName: 'Empty Fields',
    dateOfBirth: null, sex: null, bloodGroup: null, status: 'active',
    createdAt: '2026-01-05T00:00:00Z', updatedAt: null,
  },
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000201', tenantId: 'org-a', facilityId: 'fac-a2', mrn: 'MRN-A2-001', fullName: 'Chandra Thapa',
    dateOfBirth: '1978-03-22', sex: 'male', bloodGroup: 'B+', status: 'active',
    createdAt: '2026-01-03T00:00:00Z', updatedAt: null,
  },
  {
    id: 'aaaaaaaa-0000-4000-8000-000000000301', tenantId: 'org-b', facilityId: 'fac-b', mrn: 'MRN-B1-001', fullName: 'Devaki Lama',
    dateOfBirth: '1995-11-30', sex: 'female', bloodGroup: 'AB+', status: 'active',
    createdAt: '2026-01-04T00:00:00Z', updatedAt: null,
  },
];

// The live appointment statuses that occupy a slot (mirror of the Laravel
// partial unique index predicate).
const LIVE_STATUSES = ['booked', 'checked_in', 'in_consultation'];

function makeDeps(overrides = {}, {
  seedAppointments = [], seedEncounters = [], seedNotes = [], seedCharges = [],
  seedInvoices = [], seedInvoiceLines = [], seedPayments = [], seedAllocations = [],
  seedTimeline = [], seedIdentifiers = [], seedContacts = [],
  seedPolicies = [], seedPayers = [], seedConsents = [],
  seedDocuments = [], seedDepartments = [], seedBranches = [], seedLocations = [], seedWards = [], seedRooms = [], seedBeds = [],  seedStaff = [], seedServices = [], seedMedications = [], seedScheduleTemplates = [], seedScheduleExceptions = [], seedSettings = [],
} = {}) {
  // Per-instance state: each test gets a fresh appointment store + audit
  // log, so race/conflict tests share ONE deps instance across requests.
  const appointmentStore = [...seedAppointments];
  const auditEvents = [];
  // Queue-token counters (TokenIssuer parity): keyed by
  // tenant|provider|date — the simulated row-locked allocation.
  const tokenCounters = new Map();
  // Encounters created per instance (one per appointment — the simulated
  // uq_encounters_tenant_appointment backstop), plus seeded encounter
  // fixtures for the notes tests.
  const encounterStore = [...seedEncounters];
  // Clinical notes created per instance (drafts only in this phase), plus
  // seeded note fixtures for the sign tests.
  const noteStore = [...seedNotes];
  // Billing rows (Phase 15): charges derived during the issue transaction +
  // the issued invoice + its frozen lines, plus seeded fixtures for the
  // idempotency / already-invoiced / number-collision tests.
  const chargeStore = [...seedCharges];
  const invoiceStore = [...seedInvoices];
  const invoiceLineStore = [...seedInvoiceLines];
  // Patient timeline entries (Phase 26) — seeded fixtures for the timeline
  // read tests (written only by the Laravel PatientTimeline service).
  const timelineStore = [...seedTimeline];
  // Patient identifiers (Phase 28) — seeded fixtures for the identifiers
  // read tests (written only by the Laravel store flow: value encrypts into
  // value_encrypted + hashes into value_hash via the mutator).
  const identifierStore = [...seedIdentifiers];
  // Patient contacts (Phase 29) — seeded fixtures for the contacts read
  // tests (written only by the Laravel store/update flows; history is
  // preserved by superseding, never deleting).
  const contactStore = [...seedContacts];
  // Insurance policies + payers (Phase 30) — seeded fixtures for the
  // insurance read tests (written only by the Laravel store/update/cancel
  // flows; status is a lifecycle, never a deletion).
  const policyStore = [...seedPolicies];
  const payerStore = [...seedPayers];
  // Patient consents (Phase 31) — seeded fixtures for the consents read
  // tests (written only by the Laravel capture/revoke flows; versioned
  // history outlives the consent — status, never deletion).
  const consentStore = [...seedConsents];
  // Patient documents (Phase 32) — seeded fixtures for the documents read
  // tests (written only by the Laravel store flow; metadata only — no
  // object storage yet, records are honestly `staged`).
  const documentStore = [...seedDocuments];
  // Departments (Phase 33) — seeded fixtures for the organization-scoped
  // departments read tests (written only by the Laravel store flow;
  // TENANT_FACILITY_BRANCH catalog rows).
  const departmentStore = [...seedDepartments];
  // Branches (Phase 34) — seeded fixtures for the facility-scoped branches
  // read tests (written only by the Laravel store flow; TENANT_ONLY rows —
  // the facility scoping is the query, not RLS).
  const branchStore = [...seedBranches];
  // Facility settings (Phase 45) — seeded fixtures for the facility-scoped
  // settings read tests (written only by the Laravel store flow;
  // TENANT_FACILITY rows — the mapWithKeys keyed-object response, versioned
  // jsonb values; NO status column, NO soft-deletes — nothing is ever
  // excluded).
  const settingStore = [...seedSettings];
  // Locations (Phase 35) — seeded fixtures for the organization-scoped
  // locations read tests (written only by the Laravel store flow;
  // TENANT_FACILITY_BRANCH catalog rows).
  const locationStore = [...seedLocations];
  // Wards (Phase 36) — seeded fixtures for the organization-scoped wards
  // read tests (written only by the Laravel store flow;
  // TENANT_FACILITY_BRANCH catalog rows).
  const wardStore = [...seedWards];
  // Rooms (Phase 37) — seeded fixtures for the organization-scoped rooms
  // read tests (written only by the Laravel store flow;
  // TENANT_FACILITY_BRANCH catalog rows).
  const roomStore = [...seedRooms];
  // Beds (Phase 38) — seeded fixtures for the organization-scoped beds
  // read tests (written only by the Laravel store flow;
  // TENANT_FACILITY_BRANCH catalog rows; NEVER soft-deleted —
  // out_of_service is a status).
  const bedStore = [...seedBeds];
  // Staff (Phase 39) — seeded fixtures for the organization-scoped staff
  // read tests (written only by the Laravel store flow; TENANT_FACILITY
  // catalog rows — NO branch_id column; NEVER soft-deleted — departed is a
  // status).
  const staffStore = [...seedStaff];
  // Services (Phase 40) — seeded fixtures for the organization-scoped
  // services read tests (written only by the Laravel store flow;
  // TENANT_FACILITY catalog rows — NO branch_id column; SOFT-DELETABLE —
  // the read excludes deleted_at rows exactly as the SoftDeletes model
  // scope does).
  const serviceStore = [...seedServices];
  // Medications (Phase 42) — seeded fixtures for the organization-scoped
  // formulary read tests (written only by the Laravel store flow;
  // TENANT_FACILITY catalog rows — NO branch_id column; SOFT-DELETABLE —
  // the read excludes deleted_at rows exactly as the SoftDeletes model
  // scope does; NO status filter — active AND inactive both return).
  const medicationStore = [...seedMedications];
  // Schedule templates (Phase 43) — seeded fixtures for the
  // organization-scoped schedule-template read tests (written only by the
  // Laravel store flow; TENANT_FACILITY catalog rows — NO branch_id
  // column; SOFT-DELETABLE — the read excludes deleted_at rows exactly as
  // the SoftDeletes model scope does; NO status filter — active AND
  // inactive both return).
  const scheduleTemplateStore = [...seedScheduleTemplates];
  // Schedule exceptions (Phase 44) — seeded fixtures for the
  // organization-scoped schedule-exception read tests (written only by the
  // Laravel store flow; TENANT_FACILITY catalog rows — NO branch_id
  // column; NOT soft-deletable — the ScheduleException model has NO
  // SoftDeletes and the table has NO deleted_at column; NO status filter
  // — active AND cancelled both return).
  const scheduleExceptionStore = [...seedScheduleExceptions];
  // Payments + allocations (Phase 16): captured payments land together with
  // their allocation and the guarded invoice update — committed as one unit,
  // rolled back as one unit.
  const paymentStore = [...seedPayments];
  const allocationStore = [...seedAllocations];

  return {
    secret: SECRET,
    issuer: ISSUER,
    audience: AUDIENCE,
    findUserBySubject: (sub) => bySubject.get(sub) ?? null,
    loadActiveAssignments: (userId) => assignments[userId] ?? [],
    activeSupportSession: (userId) => sessions.get(userId) ?? null,
    loadOrganization: (id) => ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[id] ?? null,
    loadFacility: (id) => ({ 'fac-a1': facA1, 'fac-a2': facA2, 'fac-b': facB })[id] ?? null,
    loadBranch: (id) => ({ 'br-a1': branchA1, 'br-a2': branchA2, 'br-b': branchB })[id] ?? null,
    isPlatformRoute: (req) => new URL(req.url).pathname.startsWith('/platform'),
    // Simulated RLS filter: the rows visible to the authoritative claims,
    // presented in the exact PatientRow shape (mirror of the deployed
    // patients-list/index.ts mapping — tenant/facility scope is dropped).
    listPatients: (claims) => patients
      .filter((p) => p.tenantId === claims.app_tenant_id && p.facilityId === claims.app_facility_id)
      .map(({ tenantId, ...row }) => row),
    // Simulated RLS single-row read: visible only under matching claims
    // (mirror of the deployed patients-show wiring; null covers both
    // nonexistent and out-of-scope, which the handler maps to 404).
    showPatient: (claims, id) => {
      const row = patients.find((p) => p.id === id);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      const { tenantId, ...presented } = row;
      return presented;
    },

    // --- appointments:queue wiring (Phase 27) --------------------------

    // The server-side "today" (the exact `today()->toDateString()` default
    // for an absent `date` query parameter) — the SLOT_DATE fixture date.
    todayIso: () => SLOT_DATE,

    // Simulated RLS-scoped queue read (mirror of the deployed wiring and
    // AppointmentController::queue): tenant scope ALWAYS; facility scope
    // only when a facility claim exists (org-level sees the whole tenant);
    // the always-applied checked_in / in_consultation status filter; the
    // `date` whereDate filter (default today); the optional providerStaffId
    // exact-match filter; `order by token_no asc` (PostgreSQL ASC → NULLS
    // LAST — the exact Laravel order); the patient ref + encounter id
    // resolve under the same claims (out-of-scope → null, never a leak).
    listAppointmentQueue: (claims, filters) => {
      const facilityClaim = claims.app_facility_id === '' ? null : claims.app_facility_id;
      const refVisible = (tenantId, facilityId) =>
        tenantId === claims.app_tenant_id && (facilityClaim === null || facilityId === facilityClaim);
      return appointmentStore
        .filter((a) => a.tenantId === claims.app_tenant_id)
        .filter((a) => facilityClaim === null || a.facilityId === facilityClaim)
        .filter((a) => ['checked_in', 'in_consultation'].includes(a.status))
        .filter((a) => (a.startsAt ?? '').slice(0, 10) === filters.date)
        .filter((a) => filters.providerStaffId === undefined || a.providerStaffId === filters.providerStaffId)
        .sort((x, y) => {
          // PostgreSQL `order by token_no asc` → NULLS LAST (the ASC
          // default); equal token numbers keep the store order (no secondary
          // key in Laravel).
          if (x.tokenNo === null && y.tokenNo !== null) return 1;
          if (x.tokenNo !== null && y.tokenNo === null) return -1;
          if (x.tokenNo !== null && y.tokenNo !== null && x.tokenNo !== y.tokenNo) return x.tokenNo < y.tokenNo ? -1 : 1;
          return 0;
        })
        .map((a) => {
          const patient = patients.find((p) => p.id === a.patientId);
          const encounter = encounterStore.find((e) => e.appointmentId === a.id
            && e.tenantId === claims.app_tenant_id && (facilityClaim === null || e.facilityId === facilityClaim));
          const patientRef = patient !== undefined && refVisible(patient.tenantId, patient.facilityId)
            ? { id: patient.id, mrn: patient.mrn, fullName: patient.fullName }
            : null;
          return {
            appointmentId: a.id,
            tokenNo: a.tokenNo,
            status: a.status,
            patient: patientRef,
            startsAt: a.startsAt,
            encounterId: encounter === undefined ? null : encounter.id,
          };
        });
    },

    // --- patients:timeline wiring (Phase 26) ---------------------------

    // Simulated RLS-scoped timeline read (mirror of the deployed wiring and
    // PatientController::timeline): the patient gate decides 404 (out-of-
    // scope ≡ nonexistent → null); the entries are bound to the verified
    // patient + tenant claim (patient_timeline_entries is TENANT_ONLY),
    // ordered by occurred_at DESC then id DESC — the exact Laravel
    // `->orderByDesc('occurred_at')->orderByDesc('id')`, including the
    // PostgreSQL DESC default NULLS FIRST (a null occurred_at sorts first,
    // mirroring the real `order by occurred_at desc`); `summary` is the
    // structured jsonb payload, never clinical content.
    listPatientTimeline: (claims, id) => {
      const patient = patients.find((p) => p.id === id);
      if (patient === undefined) return null;
      if (patient.tenantId !== claims.app_tenant_id || patient.facilityId !== claims.app_facility_id) return null;
      return timelineStore
        .filter((e) => e.patientId === id && e.tenantId === claims.app_tenant_id)
        .sort((x, y) => {
          // PostgreSQL `order by occurred_at desc` → NULLS FIRST (the DESC
          // default), then `id desc`.
          const ax = x.occurredAt ?? null;
          const ay = y.occurredAt ?? null;
          if (ax === null && ay !== null) return -1;
          if (ax !== null && ay === null) return 1;
          if (ax !== null && ay !== null && ax !== ay) return ax < ay ? 1 : -1;
          return x.id < y.id ? 1 : x.id > y.id ? -1 : 0;
        })
        .map((e) => ({ id: e.id, occurredAt: e.occurredAt ?? null, eventType: e.eventType, summary: e.summary }));
    },

    // --- patients:identifiers wiring (Phase 28) ------------------------

    // Simulated RLS-scoped identifiers read (mirror of the deployed wiring
    // and PatientIdentifierController::index): the patient gate decides 404
    // (out-of-scope ≡ nonexistent → null); the identifiers are bound to the
    // verified patient + tenant claim (patient_identifiers is TENANT_ONLY),
    // ordered by created_at DESC — the exact Laravel
    // `->orderByDesc('created_at')` (the PostgreSQL DESC default NULLS
    // FIRST; distinct fixture created_at values make the order
    // deterministic — Laravel has no secondary key). NO status filter:
    // active AND superseded identifiers both return. `value` is the
    // DECRYPTED plaintext — the EncryptedString cast boundary the
    // dependency carries (the store rows hold the plaintext value; the
    // ciphertext-at-rest + hash semantics are proven at the PHP DB tier).
    listPatientIdentifiers: (claims, id) => {
      const patient = patients.find((p) => p.id === id);
      if (patient === undefined) return null;
      if (patient.tenantId !== claims.app_tenant_id || patient.facilityId !== claims.app_facility_id) return null;
      return identifierStore
        .filter((i) => i.patientId === id && i.tenantId === claims.app_tenant_id)
        .sort((x, y) => {
          const ax = x.createdAt ?? null;
          const ay = y.createdAt ?? null;
          if (ax === null && ay !== null) return -1;
          if (ax !== null && ay === null) return 1;
          if (ax !== null && ay !== null && ax !== ay) return ax < ay ? 1 : -1;
          return 0;
        })
        .map((i) => ({
          id: i.id,
          type: i.type,
          value: i.value,
          issuingCountry: i.issuingCountry ?? null,
          isVerified: i.isVerified,
          status: i.status,
        }));
    },

    // --- patients:contacts wiring (Phase 29) ---------------------------

    // Simulated RLS-scoped contacts read (mirror of the deployed wiring
    // and PatientContactController::index): the patient gate decides 404
    // (out-of-scope ≡ nonexistent → null); the contacts are bound to the
    // verified patient + tenant claim (patient_contacts is TENANT_ONLY),
    // ordered by is_primary DESC then created_at ASC — the exact Laravel
    // `->orderByDesc('is_primary')->orderBy('created_at')` (boolean DESC →
    // primary first; the ASC default NULLS LAST on the secondary key). NO
    // status filter: active AND superseded contacts both return. `address`
    // and `contactPerson` are the decoded jsonb structured payloads (the
    // 'array' casts); `value` is the plain nullable text (no encryption).
    listPatientContacts: (claims, id) => {
      const patient = patients.find((p) => p.id === id);
      if (patient === undefined) return null;
      if (patient.tenantId !== claims.app_tenant_id || patient.facilityId !== claims.app_facility_id) return null;
      return contactStore
        .filter((c) => c.patientId === id && c.tenantId === claims.app_tenant_id)
        .sort((x, y) => {
          const xp = x.isPrimary ? 1 : 0;
          const yp = y.isPrimary ? 1 : 0;
          if (xp !== yp) return yp - xp;
          const ax = x.createdAt ?? null;
          const ay = y.createdAt ?? null;
          if (ax === null && ay !== null) return 1;
          if (ax !== null && ay === null) return -1;
          if (ax !== null && ay !== null && ax !== ay) return ax < ay ? -1 : 1;
          return 0;
        })
        .map((c) => ({
          id: c.id,
          type: c.type,
          value: c.value ?? null,
          address: c.address ?? null,
          contactPerson: c.contactPerson ?? null,
          isPrimary: c.isPrimary,
          status: c.status,
        }));
    },

    // --- patients:insurance-policies wiring (Phase 30) -----------------

    // Simulated RLS-scoped policies read (mirror of the deployed wiring
    // and InsurancePolicyController::index): the patient gate decides 404
    // (out-of-scope ≡ nonexistent → null); the policies are bound to the
    // verified patient + tenant claim (insurance_policies is TENANT_ONLY),
    // ordered by created_at DESC — the exact Laravel
    // `->orderByDesc('created_at')` (the PostgreSQL DESC default NULLS
    // FIRST; distinct fixture created_at values make the order
    // deterministic — Laravel has no secondary key). NO status filter:
    // active, expired AND cancelled policies all return (status is a
    // lifecycle, never a deletion). The payer ref resolves under the SAME
    // tenant claim (payers is TENANT_ONLY — the eager `payer:id,name,code`
    // parity) — an out-of-tenant payer renders null, never a leak.
    listPatientInsurancePolicies: (claims, id) => {
      const patient = patients.find((p) => p.id === id);
      if (patient === undefined) return null;
      if (patient.tenantId !== claims.app_tenant_id || patient.facilityId !== claims.app_facility_id) return null;
      return policyStore
        .filter((p) => p.patientId === id && p.tenantId === claims.app_tenant_id)
        .sort((x, y) => {
          const ax = x.createdAt ?? null;
          const ay = y.createdAt ?? null;
          if (ax === null && ay !== null) return -1;
          if (ax !== null && ay === null) return 1;
          if (ax !== null && ay !== null && ax !== ay) return ax < ay ? 1 : -1;
          return 0;
        })
        .map((p) => {
          const payer = payerStore.find((q) => q.id === p.payerId && q.tenantId === claims.app_tenant_id) ?? null;
          return {
            id: p.id,
            patientId: p.patientId,
            payerId: p.payerId,
            payer: payer === null ? null : { id: payer.id, name: payer.name, code: payer.code },
            policyNumber: p.policyNumber,
            coverageType: p.coverageType,
            validFrom: p.validFrom ?? null,
            validTo: p.validTo ?? null,
            benefits: p.benefits,
            status: p.status,
            lockVersion: p.lockVersion,
          };
        });
    },

    // --- patients:consents wiring (Phase 31) ---------------------------

    // Simulated RLS-scoped consents read (mirror of the deployed wiring
    // and ConsentController::index): the patient gate decides 404
    // (out-of-scope ≡ nonexistent → null); the consents are bound to the
    // verified patient + tenant claim (consents is TENANT_ONLY), ordered
    // by version DESC — the exact Laravel `->orderByDesc('version')` (no
    // secondary key). NO status filter: active, revoked AND expired
    // consents all return (versioned lifecycle — history outlives the
    // consent). `scope` is the decoded jsonb payload (the 'array' cast);
    // `givenAt`/`revokedAt` ISO timestamps, nullable; `revocationReason`
    // nullable; `patientId` is contract-explicit.
    listPatientConsents: (claims, id) => {
      const patient = patients.find((p) => p.id === id);
      if (patient === undefined) return null;
      if (patient.tenantId !== claims.app_tenant_id || patient.facilityId !== claims.app_facility_id) return null;
      return consentStore
        .filter((c) => c.patientId === id && c.tenantId === claims.app_tenant_id)
        .sort((x, y) => (y.version - x.version))
        .map((c) => ({
          id: c.id,
          patientId: c.patientId,
          consentType: c.consentType,
          version: c.version,
          status: c.status,
          scope: c.scope,
          givenAt: c.givenAt ?? null,
          revokedAt: c.revokedAt ?? null,
          revocationReason: c.revocationReason ?? null,
        }));
    },

    // --- patients:documents wiring (Phase 32) --------------------------

    // Simulated RLS-scoped documents read (mirror of the deployed wiring
    // and PatientDocumentController::index): the patient gate decides 404
    // (out-of-scope ≡ nonexistent → null); the documents are bound to the
    // verified patient + tenant claim (patient_documents is TENANT_ONLY),
    // ordered by created_at DESC — the exact Laravel
    // `->orderByDesc('created_at')` (the PostgreSQL DESC default NULLS
    // FIRST; distinct fixture created_at values make the order
    // deterministic — Laravel has no secondary key). NO status filter:
    // staged, available, archived AND purged documents all return (the
    // lifecycle statuses). `mimeType`/`sizeBytes`/`checksum`/`expiresAt`/
    // `retentionClass` nullable; `uploadedAt`/`expiresAt` ISO timestamps;
    // the storage pointer `objectKey` is deliberately NOT exposed (the
    // Laravel contract does not present it — no crypto boundary).
    listPatientDocuments: (claims, id) => {
      const patient = patients.find((p) => p.id === id);
      if (patient === undefined) return null;
      if (patient.tenantId !== claims.app_tenant_id || patient.facilityId !== claims.app_facility_id) return null;
      return documentStore
        .filter((d) => d.patientId === id && d.tenantId === claims.app_tenant_id)
        .sort((x, y) => {
          const ax = x.createdAt ?? null;
          const ay = y.createdAt ?? null;
          if (ax === null && ay !== null) return -1;
          if (ax !== null && ay === null) return 1;
          if (ax !== null && ay !== null && ax !== ay) return ax < ay ? 1 : -1;
          return 0;
        })
        .map((d) => ({
          id: d.id,
          patientId: d.patientId,
          documentType: d.documentType,
          mimeType: d.mimeType ?? null,
          sizeBytes: d.sizeBytes ?? null,
          checksum: d.checksum ?? null,
          status: d.status,
          uploadedAt: d.uploadedAt ?? null,
          expiresAt: d.expiresAt ?? null,
          retentionClass: d.retentionClass ?? null,
        }));
    },

    // --- organizations:departments wiring (Phase 33) -------------------

    // Simulated RLS-scoped departments read (mirror of the deployed wiring
    // and DepartmentController::index + AccessCheck::organization): the
    // organization gate decides the NOT-FOUND classes — a nonexistent
    // organization → 'organization-not-found' (AccessCheck::organization's
    // own 404 'Organization not found.'), an organization outside the
    // authoritative tenant claim (no assignment whose tenant_id equals the
    // organization key — the organization id IS the tenant id) → null
    // (deny(read), 404 'Resource not found.' — existence never leaked;
    // platform callers bypass the scope check exactly as AccessCheck does).
    // The departments are read under the claims (departments is
    // TENANT_FACILITY_BRANCH — the select policy is `tenant_id = TENANT AND
    // (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL
    // OR branch_id = BRANCH OR BRANCH IS NULL)`), ordered by name ASC — the
    // exact `->orderBy('name')`. The facility filter is applied ONLY when
    // the caller has a facility claim (the exact `! isPlatform &&
    // facilityId() !== null` guard — org-level / platform callers see every
    // facility of the tenant). NO status filter — active AND inactive both
    // return (the catalog statuses). `facilityId`/`branchId`/
    // `parentDepartmentId` nullable.
    listOrganizationDepartments: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return departmentStore
        .filter((d) => d.tenantId === organizationId
          && (claims.app_tenant_id === '' ? false : true)
          && (claims.app_facility_id === '' || d.facilityId === claims.app_facility_id)
          && (d.branchId === null || d.branchId === claims.app_branch_id || claims.app_branch_id === ''))
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((d) => ({
          id: d.id,
          facilityId: d.facilityId ?? null,
          branchId: d.branchId ?? null,
          name: d.name,
          code: d.code,
          status: d.status,
          parentDepartmentId: d.parentDepartmentId ?? null,
        }));
    },

    // --- organizations:wards wiring (Phase 36) -------------------------

    // Simulated RLS-scoped wards read (mirror of the deployed wiring and
    // WardController::index + AccessCheck::organization): the organization
    // gate decides the NOT-FOUND classes — a nonexistent organization →
    // 'organization-not-found' (AccessCheck::organization's own 404
    // 'Organization not found.'), an organization outside the authoritative
    // tenant claim (no assignment whose tenant_id equals the organization
    // key — the organization id IS the tenant id) → null (deny(read), 404
    // 'Resource not found.' — existence never leaked; platform callers
    // bypass the scope check exactly as AccessCheck does). The wards are
    // read under the claims (wards is TENANT_FACILITY_BRANCH — the select
    // policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
    // FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR
    // BRANCH IS NULL)`), ordered by name ASC — the exact `->orderBy('name')`.
    // The facility filter is applied ONLY when the caller has a facility
    // claim (the exact `! isPlatform && facilityId() !== null` guard —
    // org-level / platform callers see every facility of the tenant). NO
    // status filter — active AND inactive both return (the lifecycle
    // statuses). `facilityId`/`branchId` nullable AND hydrated — they
    // carry the real values (the index select includes them).
    listOrganizationWards: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return wardStore
        .filter((w) => w.tenantId === organizationId
          && (claims.app_tenant_id === '' ? false : true)
          && (claims.app_facility_id === '' || w.facilityId === claims.app_facility_id)
          && (w.branchId === null || w.branchId === claims.app_branch_id || claims.app_branch_id === ''))
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((w) => ({
          id: w.id,
          facilityId: w.facilityId ?? null,
          branchId: w.branchId ?? null,
          name: w.name,
          code: w.code,
          wardType: w.wardType,
          status: w.status,
        }));
    },

    // --- organizations:beds wiring (Phase 38) --------------------------

    // Simulated RLS-scoped beds read (mirror of the deployed wiring and
    // BedController::index + AccessCheck::organization): the organization
    // gate decides the NOT-FOUND classes — a nonexistent organization →
    // 'organization-not-found' (AccessCheck::organization's own 404
    // 'Organization not found.'), an organization outside the authoritative
    // tenant claim (no assignment whose tenant_id equals the organization
    // key — the organization id IS the tenant id) → null (deny(read), 404
    // 'Resource not found.' — existence never leaked; platform callers
    // bypass the scope check exactly as AccessCheck does). The beds are
    // read under the claims (beds is TENANT_FACILITY_BRANCH — the select
    // policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
    // FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR
    // BRANCH IS NULL)`), ordered by bed_code ASC — the exact
    // `->orderBy('bed_code')`, with the eager room ref (the exact
    // `with('room:id,code,name,ward_id')`). The facility filter is applied
    // ONLY when the caller has a facility claim (the exact `! isPlatform
    // && facilityId() !== null` guard — org-level / platform callers see
    // every facility of the tenant). NO status filter — every lifecycle
    // status returns (available/occupied/reserved/cleaning/out_of_service).
    // Beds are NEVER soft-deleted (no deleted_at filter).
    // `facilityId`/`roomId` NOT NULL (base schema), `branchId` nullable
    // (tenancy_v2) — all hydrated with real values; the room ref carries
    // exactly id/code/name; `lockVersion` is CONTRACT-EXPLICIT (presented).
    listOrganizationBeds: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return bedStore
        .filter((b) => b.tenantId === organizationId
          && (claims.app_facility_id === '' || b.facilityId === claims.app_facility_id)
          && (b.branchId === null || b.branchId === claims.app_branch_id || claims.app_branch_id === ''))
        .sort((x, y) => (x.bedCode < y.bedCode ? -1 : x.bedCode > y.bedCode ? 1 : 0))
        .map((b) => ({
          id: b.id,
          facilityId: b.facilityId,
          branchId: b.branchId ?? null,
          roomId: b.roomId,
          room: b.room
            ? { id: b.room.id, code: b.room.code, name: b.room.name }
            : null,
          bedCode: b.bedCode,
          status: b.status,
          lockVersion: b.lockVersion,
        }));
    },

    // --- organizations:staff wiring (Phase 39) -------------------------

    // Simulated RLS-scoped staff read (mirror of the deployed wiring and
    // StaffController::index + AccessCheck::organization): the organization
    // gate decides the NOT-FOUND classes — a nonexistent organization →
    // 'organization-not-found' (AccessCheck::organization's own 404
    // 'Organization not found.'), an organization outside the authoritative
    // tenant claim (no assignment whose tenant_id equals the organization
    // key — the organization id IS the tenant id) → null (deny(read), 404
    // 'Resource not found.' — existence never leaked; platform callers
    // bypass the scope check exactly as AccessCheck does). The staff are
    // read under the claims — staff is **TENANT_FACILITY** (NOT
    // TENANT_FACILITY_BRANCH: no branch_id column, so the select policy is
    // `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
    // NULL)` — there is NO branch clause; a branch proposal does NOT narrow
    // this read) — ordered by full_name ASC — the exact
    // `->orderBy('full_name')`, with the eager department ref (the exact
    // `with('department:id,code,name')`). The facility filter is applied
    // ONLY when the caller has a facility claim (the exact `! isPlatform
    // && facilityId() !== null` guard — org-level / platform callers see
    // every facility of the tenant). NO status filter — active/on_leave/
    // departed all return (the staff lifecycle). Staff are NEVER
    // soft-deleted (no deleted_at filter). `facilityId`/`departmentId` NOT
    // NULL (base schema) and hydrated with real values; the department ref
    // carries exactly id/code/name; `userId`/`designation`/`hireDate`
    // nullable; `hireDate` as YYYY-MM-DD; `licenseNumberEncrypted` is never
    // present in the store (the Laravel index map does not present it).
    listOrganizationStaff: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return staffStore
        .filter((s) => s.tenantId === organizationId
          && (claims.app_facility_id === '' || s.facilityId === claims.app_facility_id))
        .sort((x, y) => (x.fullName < y.fullName ? -1 : x.fullName > y.fullName ? 1 : 0))
        .map((s) => ({
          id: s.id,
          facilityId: s.facilityId,
          departmentId: s.departmentId,
          department: s.department
            ? { id: s.department.id, code: s.department.code, name: s.department.name }
            : null,
          employeeCode: s.employeeCode,
          fullName: s.fullName,
          designation: s.designation ?? null,
          status: s.status,
          userId: s.userId ?? null,
          hireDate: s.hireDate ?? null,
        }));
    },

    // --- organizations:services wiring (Phase 40) ----------------------

    // Simulated RLS-scoped services read (mirror of the deployed wiring and
    // ServiceController::index + AccessCheck::organization): the
    // organization gate decides the NOT-FOUND classes — a nonexistent
    // organization → 'organization-not-found' (AccessCheck::organization's
    // own 404 'Organization not found.'), an organization outside the
    // authoritative tenant claim (no assignment whose tenant_id equals the
    // organization key — the organization id IS the tenant id) → null
    // (deny(read), 404 'Resource not found.' — existence never leaked;
    // platform callers bypass the scope check exactly as AccessCheck does).
    // The services are read under the claims — services is
    // **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH: no branch_id column,
    // so the select policy is `tenant_id = TENANT AND (facility_id =
    // FACILITY OR FACILITY IS NULL)` — there is NO branch clause; a branch
    // proposal does NOT narrow this read) — ordered by name ASC — the exact
    // `->orderBy('name')`, with the eager department ref (the exact
    // `with('department:id,code,name')`). The facility filter is applied
    // ONLY when the caller has a facility claim (the exact `! isPlatform
    // && facilityId() !== null` guard — org-level / platform callers see
    // every facility of the tenant). NO status filter — active AND inactive
    // both return (the catalog statuses). **Services ARE soft-deletable**
    // — the SoftDeletes model scope excludes `deletedAt`-set rows, exactly
    // reproduced here. `facilityId` NOT NULL (base schema) and hydrated
    // with the real value; `departmentId` NULLABLE (the composite FK allows
    // NULL — a service may be department-less); the department ref carries
    // exactly id/code/name; `defaultDurationMinutes`/`defaultChargeMinor`/
    // `currency` nullable; money is integer minor units.
    listOrganizationServices: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return serviceStore
        .filter((s) => s.tenantId === organizationId
          && s.deletedAt === null
          && (claims.app_facility_id === '' || s.facilityId === claims.app_facility_id))
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((s) => ({
          id: s.id,
          facilityId: s.facilityId,
          departmentId: s.departmentId ?? null,
          department: s.department
            ? { id: s.department.id, code: s.department.code, name: s.department.name }
            : null,
          name: s.name,
          code: s.code,
          serviceType: s.serviceType,
          status: s.status,
          defaultDurationMinutes: s.defaultDurationMinutes ?? null,
          defaultChargeMinor: s.defaultChargeMinor ?? null,
          currency: s.currency ?? null,
        }));
    },

    // --- organizations:payers wiring (Phase 41) ------------------------

    // Simulated RLS-scoped payers read (mirror of the deployed wiring and
    // PayerController::index + AccessCheck::organization): the organization
    // gate decides the NOT-FOUND classes — a nonexistent organization →
    // 'organization-not-found' (AccessCheck::organization's own 404
    // 'Organization not found.'), an organization outside the authoritative
    // tenant claim (no assignment whose tenant_id equals the organization
    // key — the organization id IS the tenant id) → null (deny(read), 404
    // 'Resource not found.' — existence never leaked; platform callers
    // bypass the scope check exactly as AccessCheck does). The payers are
    // read under the claims — payers is **TENANT_ONLY** (NO facility_id
    // column at all — a policy covers a patient at ANY facility of the
    // tenant, so the select policy is just `tenant_id = TENANT`; there is
    // NO facility clause AND NO facility filter in the Laravel query — the
    // `! isPlatform && facilityId() !== null` guard is ABSENT, so even a
    // facility-scoped caller sees every tenant payer) — ordered by name ASC
    // (the exact `->orderBy('name')`). NO status filter — active AND
    // inactive both return (the catalog statuses). Payer has NO SoftDeletes
    // — nothing is excluded. The exact 5-field present() map; nothing else
    // ever leaves.
    listOrganizationPayers: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return payerStore
        .filter((p) => p.tenantId === organizationId)
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((p) => ({
          id: p.id,
          name: p.name,
          code: p.code,
          payerType: p.payerType,
          status: p.status,
        }));
    },

    // --- organizations:medications wiring (Phase 42) -------------------

    // Simulated RLS-scoped medications read (mirror of the deployed wiring
    // and MedicationController::index + AccessCheck::organization): the
    // organization gate decides the NOT-FOUND classes — a nonexistent
    // organization → 'organization-not-found' (AccessCheck::organization's
    // own 404 'Organization not found.'), an organization outside the
    // authoritative tenant claim (no assignment whose tenant_id equals the
    // organization key — the organization id IS the tenant id) → null
    // (deny(read), 404 'Resource not found.' — existence never leaked;
    // platform callers bypass the scope check exactly as AccessCheck
    // does). The medications are read under the claims — medications is
    // **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH: no branch_id
    // column, so the select policy is `tenant_id = TENANT AND (facility_id
    // = FACILITY OR FACILITY IS NULL)` — there is NO branch clause; a
    // branch proposal does NOT narrow this read) — ordered by generic_name
    // ASC — the exact `->orderBy('generic_name')`. The facility filter is
    // applied ONLY when the caller has a facility claim (the exact `!
    // isPlatform && facilityId() !== null` guard — org-level / platform
    // callers see every facility of the tenant). NO status filter — active
    // AND inactive both return (the catalog statuses). **Medications ARE
    // soft-deletable** — the SoftDeletes model scope excludes `deletedAt`-set
    // rows, exactly reproduced here. `facilityId` NOT NULL (base schema)
    // and hydrated with the real value; `brandName` NULLABLE (the only
    // nullable text field); `strength`/`form`/`unit` NOT NULL (form
    // defaults to 'tablet'); `priceMinor` integer minor units (>= 0
    // CHECK); `currency` 3-char; `isControlled` boolean; `lockVersion` is
    // NEVER presented (unlike beds — the medication read does not expose
    // the optimistic-locking counter).
    listOrganizationMedications: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return medicationStore
        .filter((m) => m.tenantId === organizationId
          && m.deletedAt === null
          && (claims.app_facility_id === '' || m.facilityId === claims.app_facility_id))
        .sort((x, y) => (x.genericName < y.genericName ? -1 : x.genericName > y.genericName ? 1 : 0))
        .map((m) => ({
          id: m.id,
          facilityId: m.facilityId,
          code: m.code,
          genericName: m.genericName,
          brandName: m.brandName ?? null,
          strength: m.strength,
          form: m.form,
          unit: m.unit,
          priceMinor: m.priceMinor,
          currency: m.currency,
          isControlled: m.isControlled,
          status: m.status,
        }));
    },

    // --- organizations:schedule-templates wiring (Phase 43) ------------

    // Simulated RLS-scoped schedule-template read (mirror of the deployed
    // wiring and ScheduleController::templates + AccessCheck::organization):
    // the organization gate decides the NOT-FOUND classes — a nonexistent
    // organization → 'organization-not-found' (AccessCheck::organization's
    // own 404 'Organization not found.'), an organization outside the
    // authoritative tenant claim (no assignment whose tenant_id equals the
    // organization key — the organization id IS the tenant id) → null
    // (deny(read), 404 'Resource not found.' — existence never leaked;
    // platform callers bypass the scope check exactly as AccessCheck
    // does). The templates are read under the claims — schedule_templates
    // is **TENANT_FACILITY** (NOT TENANT_FACILITY_BRANCH: no branch_id
    // column, so the select policy is `tenant_id = TENANT AND
    // (facility_id = FACILITY OR FACILITY IS NULL)` — there is NO branch
    // clause; a branch proposal does NOT narrow this read) — ordered by
    // day_of_week ASC — the exact `->orderBy('day_of_week')`, with the
    // eager staff ref (the exact `with('staff:id,full_name,designation')`
    // — staff has NO SoftDeletes, so the ref always resolves in a
    // consistent DB). The facility filter is applied ONLY when the caller
    // has a facility claim (the exact `! isPlatform && facilityId() !==
    // null` guard — org-level / platform callers see every facility of the
    // tenant). NO status filter — active AND inactive both return (the
    // catalog statuses).
    // **Schedule templates ARE soft-deletable** — the SoftDeletes model
    // scope excludes `deletedAt`-set rows, exactly reproduced here.
    // `facilityId`/`staffId` NOT NULL (base schema) and hydrated with real
    // values; `serviceId` NULLABLE (the composite FK allows NULL — a
    // service-less template); `startsAt`/`endsAt` are the TIME columns
    // formatted H:i (the datetime cast's format — e.g. '09:00');
    // `validFrom`/`validTo` are the date casts' toDateString (`YYYY-MM-DD`;
    // validTo nullable); `dayOfWeek` ∈ 0..6 (ISO 8601 — 0 Sun .. 6 Sat);
    // `slotMinutes`/`capacity` integers; `status` ∈ active/inactive.
    listOrganizationScheduleTemplates: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return scheduleTemplateStore
        .filter((t) => t.tenantId === organizationId
          && t.deletedAt === null
          && (claims.app_facility_id === '' || t.facilityId === claims.app_facility_id))
        .sort((x, y) => (x.dayOfWeek < y.dayOfWeek ? -1 : x.dayOfWeek > y.dayOfWeek ? 1 : 0))
        .map((t) => ({
          id: t.id,
          facilityId: t.facilityId,
          staffId: t.staffId,
          staff: t.staff
            ? { id: t.staff.id, fullName: t.staff.fullName, designation: t.staff.designation }
            : null,
          serviceId: t.serviceId ?? null,
          dayOfWeek: t.dayOfWeek,
          startsAt: t.startsAt,
          endsAt: t.endsAt,
          slotMinutes: t.slotMinutes,
          capacity: t.capacity,
          validFrom: t.validFrom,
          validTo: t.validTo ?? null,
          status: t.status,
        }));
    },

    // --- organizations:schedule-exceptions wiring (Phase 44) ----------

    // Simulated RLS-scoped schedule-exception read (mirror of the deployed
    // wiring and ScheduleController::exceptions + AccessCheck::organization):
    // the organization gate decides the NOT-FOUND classes — a nonexistent
    // organization → 'organization-not-found' (AccessCheck::organization's
    // own 404 'Organization not found.'), an organization outside the
    // authoritative tenant claim → null (deny(read), 404 'Resource not
    // found.' — existence never leaked; platform callers bypass the scope
    // check exactly as AccessCheck does). The exceptions are read under the
    // claims (schedule_exceptions is **TENANT_FACILITY** — the select
    // policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
    // FACILITY IS NULL)` — NO branch clause, no branch_id column), ordered
    // by `exception_date` DESC — the exact `->orderByDesc('exception_date')`.
    // The facility filter is applied ONLY when the caller has a facility
    // claim (the exact `! isPlatform && facilityId() !== null` guard —
    // org-level / platform callers see every facility of the tenant). The
    // staff eager load (`with('staff:id,full_name')`) is a query-level
    // detail — the staff reference is NOT presented (presentException
    // exposes no staff ref). NO status filter — active AND cancelled both
    // return (the CHECK-constrained lifecycle statuses). NOT soft-deletable
    // — no SoftDeletes, no deleted_at column. `facilityId`/`staffId` NOT
    // NULL (base schema) and hydrated with real values; `exceptionDate`
    // `YYYY-MM-DD`; `reason` ∈ leave/holiday/block; `status` ∈
    // active/cancelled.
    listOrganizationScheduleExceptions: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return scheduleExceptionStore
        .filter((e) => e.tenantId === organizationId
          && (claims.app_facility_id === '' || e.facilityId === claims.app_facility_id))
        .sort((x, y) => (x.exceptionDate > y.exceptionDate ? -1 : x.exceptionDate < y.exceptionDate ? 1 : 0))
        .map((e) => ({
          id: e.id,
          facilityId: e.facilityId,
          staffId: e.staffId,
          exceptionDate: e.exceptionDate,
          reason: e.reason,
          status: e.status,
        }));
    },

    // --- organizations:rooms wiring (Phase 37) -------------------------

    // Simulated RLS-scoped rooms read (mirror of the deployed wiring and
    // RoomController::index + AccessCheck::organization): the organization
    // gate decides the NOT-FOUND classes — a nonexistent organization →
    // 'organization-not-found' (AccessCheck::organization's own 404
    // 'Organization not found.'), an organization outside the authoritative
    // tenant claim (no assignment whose tenant_id equals the organization
    // key — the organization id IS the tenant id) → null (deny(read), 404
    // 'Resource not found.' — existence never leaked; platform callers
    // bypass the scope check exactly as AccessCheck does). The rooms are
    // read under the claims (rooms is TENANT_FACILITY_BRANCH — the select
    // policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
    // FACILITY IS NULL) AND (branch_id IS NULL OR branch_id = BRANCH OR
    // BRANCH IS NULL)`), ordered by name ASC — the exact `->orderBy('name')`,
    // with the eager ward ref (the exact `with('ward:id,code,name')`). The
    // facility filter is applied ONLY when the caller has a facility claim
    // (the exact `! isPlatform && facilityId() !== null` guard — org-level
    // / platform callers see every facility of the tenant). NO status
    // filter — active AND inactive both return (the lifecycle statuses).
    // `facilityId`/`wardId` NOT NULL (base schema), `branchId` nullable
    // (tenancy_v2) — all hydrated with real values; the ward ref carries
    // exactly id/code/name.
    listOrganizationRooms: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return roomStore
        .filter((r) => r.tenantId === organizationId
          && (claims.app_facility_id === '' || r.facilityId === claims.app_facility_id)
          && (r.branchId === null || r.branchId === claims.app_branch_id || claims.app_branch_id === ''))
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((r) => ({
          id: r.id,
          facilityId: r.facilityId,
          branchId: r.branchId ?? null,
          wardId: r.wardId,
          ward: r.ward
            ? { id: r.ward.id, code: r.ward.code, name: r.ward.name }
            : null,
          name: r.name,
          code: r.code,
          roomType: r.roomType,
          dailyRateMinor: r.dailyRateMinor ?? null,
          currency: r.currency ?? null,
          status: r.status,
        }));
    },

    // --- organizations:locations wiring (Phase 35) ---------------------

    // Simulated RLS-scoped locations read (mirror of the deployed wiring
    // and LocationController::index + AccessCheck::organization): the
    // organization gate decides the NOT-FOUND classes — a nonexistent
    // organization → 'organization-not-found' (AccessCheck::organization's
    // own 404 'Organization not found.'), an organization outside the
    // authoritative tenant claim (no assignment whose tenant_id equals the
    // organization key — the organization id IS the tenant id) → null
    // (deny(read), 404 'Resource not found.' — existence never leaked;
    // platform callers bypass the scope check exactly as AccessCheck does).
    // The locations are read under the claims (locations is
    // TENANT_FACILITY_BRANCH — the select policy is `tenant_id = TENANT AND
    // (facility_id = FACILITY OR FACILITY IS NULL) AND (branch_id IS NULL
    // OR branch_id = BRANCH OR BRANCH IS NULL)`), ordered by name ASC — the
    // exact `->orderBy('name')`. The facility filter is applied ONLY when
    // the caller has a facility claim (the exact `! isPlatform &&
    // facilityId() !== null` guard — org-level / platform callers see every
    // facility of the tenant). NO status filter — active AND inactive both
    // return (the catalog statuses). `facilityId`/`branchId` nullable AND
    // hydrated — they carry the real values (the index select includes
    // them).
    listOrganizationLocations: (claims, organizationId) => {
      const organization = ({ 'org-a': orgA, 'org-b': orgB, 'org-suspended': orgSuspended })[organizationId] ?? null;
      if (organization === null) return 'organization-not-found';
      if (claims.app_is_platform !== 'true' && claims.app_tenant_id !== organizationId) return null;
      return locationStore
        .filter((l) => l.tenantId === organizationId
          && (claims.app_tenant_id === '' ? false : true)
          && (claims.app_facility_id === '' || l.facilityId === claims.app_facility_id)
          && (l.branchId === null || l.branchId === claims.app_branch_id || claims.app_branch_id === ''))
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((l) => ({
          id: l.id,
          facilityId: l.facilityId ?? null,
          branchId: l.branchId ?? null,
          name: l.name,
          code: l.code,
          type: l.type,
          status: l.status,
        }));
    },

    // --- facilities:branches wiring (Phase 34) -------------------------

    // Simulated RLS-scoped branches read (mirror of the deployed wiring
    // and BranchController::index + AccessCheck::facility): the facility
    // gate decides the NOT-FOUND classes — a nonexistent facility →
    // 'facility-not-found' (AccessCheck::facility's own 404 'Facility not
    // found.'), a facility outside the authoritative scope (another tenant,
    // or a facility-scoped principal requesting another facility) → null
    // (deny(read), 404 'Resource not found.' — existence never leaked;
    // platform callers bypass the scope check exactly as AccessCheck does;
    // org-level claims may read any in-tenant facility). The branches are
    // bound to the VERIFIED facility (branches is TENANT_ONLY — the select
    // policy is `tenant_id = TENANT`; the facility scoping IS the query,
    // the exact `->where('facility_id', $facility->getKey())`), ordered by
    // name ASC — the exact `->orderBy('name')`. NO status filter — active
    // AND inactive both return. **`facilityId` renders null** — the Laravel
    // index query hydrates only id/name/code/status and `present()` reads
    // an un-hydrated attribute (the literal index output).
    listFacilityBranches: (claims, facilityId) => {
      const facility = ({ 'fac-a1': facA1, 'fac-a2': facA2, 'fac-b': facB })[facilityId] ?? null;
      if (facility === null) return 'facility-not-found';
      if (claims.app_is_platform !== 'true') {
        if (claims.app_tenant_id !== facility.tenantId) return null;
        if (claims.app_facility_id !== '' && claims.app_facility_id !== facilityId) return null;
      }
      return branchStore
        .filter((b) => b.tenantId === facility.tenantId && b.facilityId === facilityId)
        .sort((x, y) => (x.name < y.name ? -1 : x.name > y.name ? 1 : 0))
        .map((b) => ({
          id: b.id,
          facilityId: null,
          name: b.name,
          code: b.code,
          status: b.status,
        }));
    },

    // --- facilities:settings wiring (Phase 45) -------------------------

    // Simulated RLS-scoped settings read (mirror of the deployed wiring
    // and FacilitySettingsController::index + AccessCheck::facility): the
    // facility gate decides the NOT-FOUND classes — a nonexistent facility
    // → 'facility-not-found' (AccessCheck::facility's own 404 'Facility
    // not found.'), a facility outside the authoritative scope (another
    // tenant, or a facility-scoped principal requesting another facility)
    // → null (deny(read), 404 'Resource not found.' — existence never
    // leaked; platform callers bypass the scope check exactly as
    // AccessCheck does). The settings are read under the claims
    // (facility_settings is **TENANT_FACILITY** — the select policy is
    // `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
    // NULL)`; the facility scoping is BOTH the query — the verified-facility
    // binding — AND the RLS facility clause), ordered by key ASC — the
    // exact `->orderBy('key')`. The result is the mapWithKeys OBJECT keyed
    // by setting key (never an array), each entry exactly {value, version,
    // updatedAt}: `value` the decoded jsonb payload, `version` the integer
    // counter, `updatedAt` the toIso8601String timestamp ('+00:00' offset)
    // or null. NO status field exists, NO soft-deletes — nothing is ever
    // excluded.
    listFacilitySettings: (claims, facilityId) => {
      const facility = ({ 'fac-a1': facA1, 'fac-a2': facA2, 'fac-b': facB })[facilityId] ?? null;
      if (facility === null) return 'facility-not-found';
      if (claims.app_is_platform !== 'true') {
        if (claims.app_tenant_id !== facility.tenantId) return null;
        if (claims.app_facility_id !== '' && claims.app_facility_id !== facilityId) return null;
      }
      return settingStore
        .filter((s) => s.tenantId === facility.tenantId && s.facilityId === facilityId)
        .sort((x, y) => (x.key < y.key ? -1 : x.key > y.key ? 1 : 0))
        .reduce((map, s) => {
          map[s.key] = {
            value: s.value,
            version: s.version,
            updatedAt: s.updatedAt === null ? null : s.updatedAt,
          };
          return map;
        }, {});
    },

    // --- appointments:create wiring (Phase 9) --------------------------

    // Simulated RLS-scoped patient lookup (mirror of the deployed wiring;
    // out-of-scope ≡ nonexistent → the handler maps both to 404).
    findPatientByScope: (claims, id) => {
      const row = patients.find((p) => p.id === id);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      return { id: row.id, mrn: row.mrn, fullName: row.fullName };
    },

    // Simulated RLS-scoped provider lookup (mirror of the deployed wiring;
    // out-of-scope ≡ nonexistent → 404).
    findProviderByScope: (claims, id) => {
      const row = staff.find((s) => s.id === id);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      return { id: row.id, fullName: row.fullName, facilityId: row.facilityId };
    },

    // Simulated schedule facts (mirror of the deployed loadSchedule SQL). A
    // provider outside the claims scope has no schedule → fail closed.
    loadSchedule: (claims, providerStaffId, date) => {
      const provider = staff.find((s) => s.id === providerStaffId);
      if (provider === undefined) {
        return { exceptionActive: true, templates: [], holdings: [] };
      }
      if (provider.tenantId !== claims.app_tenant_id || provider.facilityId !== claims.app_facility_id) {
        return { exceptionActive: true, templates: [], holdings: [] };
      }
      return schedules[`${providerStaffId}|${date}`] ?? { exceptionActive: true, templates: [], holdings: [] };
    },

    // Simulated transactional INSERT with the UNIQUE-INDEX race (mirror of
    // the deployed wiring): one live booking per tenant+provider+start — the
    // second attempt returns SLOT_TAKEN, exactly as uq_appointments_tenant_
    // provider_start would surface a unique violation.
    createAppointment: (input) => {
      const liveKey = `${input.tenantId}|${input.providerStaffId}|${input.startsAt}`;
      const existing = appointmentStore.find((a) =>
        a.tenantId === input.tenantId && a.providerStaffId === input.providerStaffId
        && a.startsAt === input.startsAt && LIVE_STATUSES.includes(a.status));
      if (existing !== undefined) return { ok: false, reason: 'SLOT_TAKEN' };
      const appointment = {
        id: 'aaaaaaaa-0000-4000-8000-0000000009' + String(appointmentStore.length + 1).padStart(2, '0'),
        facilityId: input.facilityId,
        patientId: input.patientId,
        providerStaffId: input.providerStaffId,
        serviceId: input.serviceId,
        appointmentType: input.appointmentType,
        startsAt: input.startsAt,
        endsAt: input.endsAt,
        status: 'booked',
        tokenNo: null,
        source: input.source,
        cancelReason: null,
        lockVersion: 0,
        createdAt: '2026-03-02T09:00:01Z',
        updatedAt: null,
      };
      appointmentStore.push({ ...appointment, tenantId: input.tenantId });
      return { ok: true, appointment };
    },

    // Simulated append-only audit collector (mirror of the deployed
    // recordAudit / AuditLogger): facts only, attributed to the actor + the
    // authoritative tenant/facility + correlation id.
    recordAudit: (event) => {
      auditEvents.push({ ...event, occurredAt: '2026-03-02T09:00:01Z' });
    },
    // --- appointments:checkin wiring (Phase 10) ------------------------

    // Simulated RLS-scoped appointment lookup (mirror of the deployed
    // wiring; out-of-scope ≡ nonexistent → 404).
    findAppointmentByScope: (claims, id) => {
      const row = appointmentStore.find((a) => a.id === id);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      const { tenantId, ...presented } = row;
      return presented;
    },

    // Simulated ATOMIC check-in (mirror of the deployed wiring): the
    // counter row lock serializes token minting per tenant|provider|date
    // (no duplicate tokens), and the guarded status transition means a
    // second check-in of the same appointment returns NOT_BOOKED (the DB
    // guard — never a JS check). No partial mutation on failure.
    checkInAppointment: (input) => {
      const appointment = appointmentStore.find((a) => a.id === input.appointmentId);
      if (appointment === undefined) return { ok: false, reason: 'NOT_BOOKED' };
      if (appointment.status !== 'booked') return { ok: false, reason: 'NOT_BOOKED' };
      const key = `${input.tenantId}|${input.providerStaffId}|${input.date}`;
      const next = (tokenCounters.get(key) ?? 0) + 1;
      tokenCounters.set(key, next);
      appointment.status = 'checked_in';
      appointment.tokenNo = next;
      appointment.checkedInBy = input.checkedInBy;
      appointment.lockVersion += 1;
      return { ok: true, appointment: { ...appointment } };
    },

    // --- appointments:index wiring (Phase 22) -------------------------

    // Simulated RLS-scoped appointment list (mirror of the deployed wiring
    // and AppointmentController::index): tenant scope ALWAYS; the facility
    // claim narrows to one facility, and an org-level claim ('' → null) sees
    // EVERY facility of the tenant (RLS facilityClause parity); the date /
    // providerStaffId filters apply only when present; ordering is
    // `starts_at` ascending (the only Laravel ordering key); the
    // patient/provider refs resolve under the SAME claims — an out-of-scope
    // related row renders null, never a leak.
    listAppointments: (claims, filters = {}) => {
      const facilityClaim = claims.app_facility_id === '' ? null : claims.app_facility_id;
      const refVisible = (tenantId, facilityId) =>
        tenantId === claims.app_tenant_id && (facilityClaim === null || facilityId === facilityClaim);
      return appointmentStore
        .filter((a) => a.tenantId === claims.app_tenant_id)
        .filter((a) => facilityClaim === null || a.facilityId === facilityClaim)
        .filter((a) => filters.date === undefined || (a.startsAt ?? '').slice(0, 10) === filters.date)
        .filter((a) => filters.providerStaffId === undefined || a.providerStaffId === filters.providerStaffId)
        .sort((x, y) => (x.startsAt < y.startsAt ? -1 : x.startsAt > y.startsAt ? 1 : 0))
        .map((a) => {
          const patient = patients.find((p) => p.id === a.patientId);
          const provider = staff.find((s) => s.id === a.providerStaffId);
          const patientRef = patient !== undefined && refVisible(patient.tenantId, patient.facilityId)
            ? { id: patient.id, mrn: patient.mrn, fullName: patient.fullName }
            : null;
          const providerRef = provider !== undefined && refVisible(provider.tenantId, provider.facilityId)
            ? { id: provider.id, fullName: provider.fullName, facilityId: provider.facilityId }
            : null;
          const { tenantId, ...presented } = a;
          return { appointment: presented, patient: patientRef, provider: providerRef };
        });
    },

    // --- patients:search wiring (Phase 23) ----------------------------

    // Simulated RLS-scoped patient search (mirror of the deployed wiring and
    // PatientController::search): tenant scope ALWAYS; `status = 'active'`;
    // facility scope only when a facility claim exists ('' → org-level →
    // whole tenant — RLS facilityClause parity); the case-insensitive
    // name-substring / MRN-prefix match with SQL LIKE wildcard parity (% /
    // _ unescaped, exactly like the deployed SQL); the pg_trgm
    // similarity(lower(full_name), q) score; score DESC order; hard LIMIT 20.
    // (The real pg_trgm scores and the status filter are proven at the DB
    // tier — this is the deterministic harness simulation.)
    searchPatients: (claims, q) => {
      const facilityClaim = claims.app_facility_id === '' ? null : claims.app_facility_id;
      const lowerQ = q.toLowerCase();
      return patients
        .filter((p) => p.tenantId === claims.app_tenant_id)
        .filter((p) => facilityClaim === null || p.facilityId === facilityClaim)
        .filter((p) => p.status === 'active')
        .filter((p) => sqlLike(p.fullName.toLowerCase(), `%${lowerQ}%`) || sqlLike(p.mrn.toLowerCase(), `${lowerQ}%`))
        .map((p) => ({ patient: p, score: trigramSimilarity(p.fullName.toLowerCase(), lowerQ) }))
        .sort((a, b) => b.score - a.score)
        .slice(0, 20)
        .map(({ patient: p, score }) => ({
          id: p.id,
          mrn: p.mrn,
          fullName: p.fullName,
          dateOfBirth: p.dateOfBirth,
          sex: p.sex,
          facilityId: p.facilityId,
          score: Math.round(score * 10_000) / 10_000,
        }));
    },

    // --- encounters:notes wiring (Phase 25) ---------------------------

    // Simulated RLS-scoped notes read (mirror of the deployed wiring and
    // EncounterController::notes): the encounter gate decides 404 (out-of-
    // scope ≡ nonexistent → null); the notes are bound to the verified
    // encounter + tenant claim (clinical_notes is TENANT_ONLY), ordered by
    // created_at ascending (the exact Laravel order — all statuses incl.
    // draft); the author ref resolves under the same claims (staff is
    // TENANT_FACILITY) — an out-of-scope author renders null.
    listEncounterNotes: (claims, id) => {
      const encounter = encounterStore.find((e) => e.id === id);
      if (encounter === undefined) return null;
      if (encounter.tenantId !== claims.app_tenant_id || encounter.facilityId !== claims.app_facility_id) return null;
      const facilityClaim = claims.app_facility_id === '' ? null : claims.app_facility_id;
      const authorVisible = (row) => row !== undefined
        && row.tenantId === claims.app_tenant_id
        && (facilityClaim === null || row.facilityId === facilityClaim);
      return noteStore
        .filter((n) => n.encounterId === id && n.tenantId === claims.app_tenant_id)
        .sort((x, y) => (x.createdAt ?? '') < (y.createdAt ?? '') ? -1 : (x.createdAt ?? '') > (y.createdAt ?? '') ? 1 : 0)
        .map((n) => {
          const author = staff.find((s) => s.id === n.authorStaffId);
          return {
            id: n.id,
            noteType: n.noteType,
            author: authorVisible(author) ? { id: author.id, fullName: author.fullName } : null,
            content: n.content,
            status: n.status,
            signedAt: n.signedAt ?? null,
          };
        });
    },

    // --- encounters:create wiring (Phase 11) --------------------------

    // Simulated RLS-scoped encounter lookup (mirror of the deployed wiring;
    // out-of-scope ≡ nonexistent → 404). Reads seeded encounter fixtures
    // plus encounters created by startEncounter.
    findEncounterByScope: (claims, id) => {
      const row = encounterStore.find((e) => e.id === id);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      const { tenantId, ...presented } = row;
      return presented;
    },

    // Simulated ATOMIC start (mirror of the deployed wiring): the guarded
    // status transition (`status = 'in_consultation'` only from
    // 'checked_in') and the encounter INSERT happen together; a second start
    // of the same appointment returns NOT_CHECKED_IN (the DB guard — never
    // a JS check), and the partial unique index backstop is simulated as
    // ALREADY_STARTED (one encounter per appointment). No partial mutation
    // on failure.
    startEncounter: (input) => {
      const appointment = appointmentStore.find((a) => a.id === input.appointmentId);
      if (appointment === undefined) return { ok: false, reason: 'NOT_CHECKED_IN' };
      if (appointment.status !== 'checked_in') return { ok: false, reason: 'NOT_CHECKED_IN' };
      const existing = encounterStore.find((e) => e.appointmentId === input.appointmentId);
      if (existing !== undefined) return { ok: false, reason: 'ALREADY_STARTED' };
      appointment.status = 'in_consultation';
      appointment.lockVersion += 1;
      const encounter = {
        id: 'aaaaaaaa-0000-4000-8000-000000000b' + String(encounterStore.length + 1).padStart(2, '0'),
        facilityId: input.facilityId,
        patientId: input.patientId,
        appointmentId: input.appointmentId,
        providerStaffId: input.providerStaffId,
        type: 'opd',
        status: 'open',
        startedAt: '2026-03-02T09:05:01Z',
        endedAt: null,
        signedAt: null,
        lockVersion: 0,
      };
      encounterStore.push({ ...encounter });
      return { ok: true, encounter };
    },

    // --- encounter-notes:draft wiring (Phase 12) ----------------------

    // Simulated clinical-author rule (mirror of the deployed wiring + the
    // Laravel currentProvider rule): the actor's staff record (staff.user_id
    // = actor, tenant-bound, not departed) must BE the encounter's provider.
    // The client can never supply author_staff_id.
    findAuthorStaff: (claims, actorUserId, tenantId, providerStaffId) => {
      const row = staff.find((s) => s.id === providerStaffId);
      if (row === undefined) return null;
      if (row.userId !== actorUserId) return null;
      if (row.tenantId !== tenantId) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      return { id: row.id, fullName: row.fullName, facilityId: row.facilityId };
    },

    // Simulated draft INSERT (mirror of the deployed wiring): server-derived
    // fields only, status 'draft', lock_version 0. Multiple drafts per
    // encounter are permitted (no unique index) — a plain append.
    createDraftNote: (input) => {
      const note = {
        id: 'aaaaaaaa-0000-4000-8000-000000000c' + String(noteStore.length + 1).padStart(2, '0'),
        tenantId: input.tenantId,
        facilityId: input.facilityId,
        encounterId: input.encounterId,
        noteType: input.noteType,
        authorStaffId: input.authorStaffId,
        content: input.content,
        status: 'draft',
      };
      noteStore.push({ ...note });
      const { tenantId, facilityId, ...presented } = note;
      return { ok: true, note: presented };
    },

    // --- encounters:sign wiring (Phase 14) ---------------------------

    // Simulated required signed-note check (mirror of the deployed wiring + 
    // EncounterController::sign parity): at least one SIGNED note on the
    // encounter (existence only — the note-author rule was enforced at
    // note-sign time). The signed-note set can only grow, never shrink.
    hasSignedNote: (claims, encounterId) =>
      noteStore.some((n) => n.encounterId === encounterId && n.tenantId === claims.app_tenant_id && n.status === 'signed'),

    // Simulated ATOMIC signing transaction (mirror of the deployed wiring):
    // the guarded encounter transition (`status = 'signed'` only from
    // 'open', ended_at/signed_at server-side, signed_by = actor, lock_version
    // +1) plus the GUARDED appointment handoff (`status = 'completed'` only
    // from 'in_consultation' — any other state is a silent skip, Laravel
    // parity). A second sign of the same encounter returns NOT_OPEN (the DB
    // guard — never a JS check); no partial mutation survives a failure.
    signEncounter: (input) => {
      const encounter = encounterStore.find((e) => e.id === input.encounterId);
      if (encounter === undefined) return { ok: false, reason: 'NOT_OPEN' };
      if (encounter.status !== 'open') return { ok: false, reason: 'NOT_OPEN' };
      encounter.status = 'signed';
      encounter.endedAt = '2026-03-02T11:00:00Z';
      encounter.signedAt = '2026-03-02T11:00:00Z';
      encounter.signedBy = input.signedBy;
      encounter.lockVersion += 1;
      if (input.appointmentId !== null) {
        const appt = appointmentStore.find((a) => a.id === input.appointmentId);
        if (appt !== undefined && appt.status === 'in_consultation') {
          appt.status = 'completed';
          appt.lockVersion += 1;
        }
      }
      return { ok: true, encounter: { ...encounter } };
    },

    // --- encounter-notes:sign wiring (Phase 13) -----------------------

    // Simulated RLS-scoped note lookup bound to the encounter (mirror of the
    // deployed wiring): clinical_notes is tenant-only RLS, so visibility is
    // tenant-scoped; the note must also belong to the encounter (a note of a
    // different encounter is the same null → 404).
    findNoteByScope: (claims, encounterId, noteId) => {
      const row = noteStore.find((n) => n.id === noteId && n.encounterId === encounterId);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id) return null;
      return {
        id: row.id, encounterId: row.encounterId, authorStaffId: row.authorStaffId,
        status: row.status, signedAt: row.signedAt, lockVersion: row.lockVersion,
      };
    },

    // Simulated ATOMIC signing transition (mirror of the deployed wiring):
    // the guarded status change (`status = 'signed'` only from 'draft') — a
    // second sign of the same note returns NOT_DRAFT (the DB guard — never a
    // JS check), signed_at is generated server-side, and no partial mutation
    // survives a failure.
    signNote: (input) => {
      const note = noteStore.find((n) => n.id === input.noteId);
      if (note === undefined) return { ok: false, reason: 'NOT_DRAFT' };
      if (note.status !== 'draft') return { ok: false, reason: 'NOT_DRAFT' };
      note.status = 'signed';
      note.signedAt = '2026-03-02T10:00:00Z';
      note.lockVersion += 1;
      return {
        ok: true,
        note: {
          id: note.id, encounterId: note.encounterId, authorStaffId: note.authorStaffId,
          status: note.status, signedAt: note.signedAt, lockVersion: note.lockVersion,
        },
      };
    },

    // --- encounters:invoice wiring (Phase 15) -------------------------

    // Simulated ATOMIC issue transaction (mirror of the deployed wiring + the
    // Laravel EncounterController::invoice + BillingService::issueInvoice
    // contract): the derived charges are held transactionally and committed
    // only on success — a failed issue leaves NO partial charges/invoice/
    // lines (rollback parity). The uniqueness backstops are simulated:
    //   ALREADY_INVOICED — a posted charge already sits on another invoice
    //     (the partial unique index uq_invoice_lines_tenant_charge),
    //     including a racing second issue;
    //   NUMBER_COLLISION — the server-generated number already exists on
    //     another invoice in the tenant (uq_invoices_tenant_number).
    issueInvoice: (input) => {
      // (a) The encounter must still be signed (defense-in-depth — signed is
      // a terminal state).
      const encounter = encounterStore.find((e) => e.id === input.encounterId);
      if (encounter === undefined || encounter.status !== 'signed') return { ok: false, reason: 'NOT_SIGNED' };

      // Derived rows of this transaction — pushed to the store only on
      // success (mirror of rollback).
      const derivedCharges = [];

      // (b) Consultation charge — derived from the appointment's service rate
      // ONLY when no encounter-source charge exists yet (idempotent,
      // Laravel parity). Integer minor units end to end.
      const consultationExists = chargeStore.some(
        (c) => c.tenantId === input.tenantId && c.encounterId === input.encounterId && c.sourceType === 'encounter');
      if (!consultationExists) {
        const appointment = appointmentStore.find((a) => a.id === input.appointmentId);
        const service = appointment === undefined ? undefined : services.find((s) => s.id === appointment.serviceId);
        if (service !== undefined && service.defaultChargeMinor !== null) {
          derivedCharges.push({
            id: 'aaaaaaaa-0000-4000-8000-000000000f' + String(chargeStore.length + derivedCharges.length + 1).padStart(2, '0'),
            tenantId: input.tenantId, facilityId: input.facilityId, patientId: input.patientId,
            encounterId: input.encounterId, prescriptionId: null, sourceType: 'encounter',
            description: `${service.name} — consultation`, amountMinor: service.defaultChargeMinor,
            currency: service.currency ?? 'NPR', taxRateBps: 0, status: 'posted',
          });
        }
      }

      // (c) Prescription-line charges — ordered lines × medication price,
      // quantity = max(1, quantity_minor ?? 1); skipped when the encounter's
      // FIRST prescription is already charged (Laravel anchors the check on
      // the first prescription id). Lines with a missing medication are
      // skipped (`$line->medication === null` → continue).
      const encounterPrescriptions = prescriptions
        .filter((p) => p.tenantId === input.tenantId && p.encounterId === input.encounterId)
        .sort((a, b) => a.id.localeCompare(b.id));
      const firstPrescription = encounterPrescriptions[0];
      if (firstPrescription !== undefined) {
        const alreadyCharged = chargeStore.some(
          (c) => c.tenantId === input.tenantId && c.prescriptionId === firstPrescription.id);
        if (!alreadyCharged) {
          for (const p of encounterPrescriptions) {
            for (const line of p.lines) {
              const medication = medications.find((m) => m.id === line.medicationId);
              if (line.status !== 'ordered' || medication === undefined) continue;
              const quantity = Math.max(1, line.quantityMinor ?? 1);
              derivedCharges.push({
                id: 'aaaaaaaa-0000-4000-8000-000000000f' + String(chargeStore.length + derivedCharges.length + 1).padStart(2, '0'),
                tenantId: input.tenantId, facilityId: input.facilityId, patientId: input.patientId,
                encounterId: input.encounterId, prescriptionId: p.id, sourceType: 'prescription',
                description: `${medication.genericName} (${medication.strength}) × ${quantity}`,
                amountMinor: medication.priceMinor * quantity,
                currency: medication.currency, taxRateBps: 0, status: 'posted',
              });
            }
          }
        }
      }

      // (d) Posted charges for this encounter — the invoice is built ONLY
      // from posted charges.
      const charges = [...chargeStore, ...derivedCharges].filter(
        (c) => c.tenantId === input.tenantId && c.encounterId === input.encounterId && c.status === 'posted');
      if (charges.length === 0) return { ok: false, reason: 'NO_CHARGES' };

      // (e) A posted charge is invoiced at most once (pre-check + the
      // partial unique index backstop under concurrency).
      const chargeIds = charges.map((c) => c.id);
      if (invoiceLineStore.some((l) => l.tenantId === input.tenantId && chargeIds.includes(l.chargeId))) {
        return { ok: false, reason: 'ALREADY_INVOICED' };
      }

      // (f) Server-generated invoice number (BillingService::nextNumber
      // parity — format INV-YYYYMMDD-XXXXX; deterministic per harness run).
      const invoiceNumber = 'INV-20260302-10001';
      if (invoiceStore.some((i) => i.tenantId === input.tenantId && i.invoiceNumber === invoiceNumber)) {
        return { ok: false, reason: 'NUMBER_COLLISION' };
      }

      const totalMinor = charges.reduce((sum, c) => sum + c.amountMinor, 0);
      const totalTaxMinor = charges.reduce((sum, c) => sum + Math.round((c.amountMinor * c.taxRateBps) / 10000), 0);

      // COMMIT — everything lands together (no partial mutation on failure).
      chargeStore.push(...derivedCharges);
      const invoice = {
        id: 'aaaaaaaa-0000-4000-8000-000000000g' + String(invoiceStore.length + 1).padStart(2, '0'),
        tenantId: input.tenantId, facilityId: input.facilityId, patientId: input.patientId,
        invoiceNumber, status: 'issued', totalMinor, totalTaxMinor, paidMinor: 0, lockVersion: 0,
      };
      invoiceStore.push(invoice);
      charges.forEach((charge, index) => {
        invoiceLineStore.push({
          id: 'aaaaaaaa-0000-4000-8000-000000000h' + String(invoiceLineStore.length + 1).padStart(2, '0'),
          tenantId: input.tenantId, invoiceId: invoice.id, chargeId: charge.id,
          description: charge.description, amountMinor: charge.amountMinor,
          taxMinor: Math.round((charge.amountMinor * charge.taxRateBps) / 10000), lineNo: index + 1,
        });
      });

      return {
        ok: true,
        lineCount: charges.length,
        invoice: {
          id: invoice.id, invoiceNumber, status: invoice.status, totalMinor, totalTaxMinor,
          paidMinor: 0,
          lines: charges.map((charge) => ({
            description: charge.description, amountMinor: charge.amountMinor,
            taxMinor: Math.round((charge.amountMinor * charge.taxRateBps) / 10000),
          })),
        },
      };
    },

    // --- invoices:pay wiring (Phase 16) ------------------------------

    // Simulated RLS-scoped invoice lookup (mirror of the deployed wiring;
    // out-of-scope ≡ nonexistent → 404). Returns the full InvoicePayRow
    // including the lock_version the guarded update will compare against.
    findInvoiceByScope: (claims, id) => {
      const row = invoiceStore.find((i) => i.id === id);
      if (row === undefined) return null;
      if (row.tenantId !== claims.app_tenant_id || row.facilityId !== claims.app_facility_id) return null;
      return {
        id: row.id, facilityId: row.facilityId, patientId: row.patientId,
        invoiceNumber: row.invoiceNumber, status: row.status,
        totalMinor: row.totalMinor, totalTaxMinor: row.totalTaxMinor,
        paidMinor: row.paidMinor, lockVersion: row.lockVersion,
      };
    },

    // Simulated ATOMIC capture (mirror of the deployed wiring + the Laravel
    // BillingService::capturePayment contract): IDEMPOTENCY FIRST (same
    // tenant + key → replay, no new money, no eligibility checks), then
    // eligibility (voided/already-paid/positive-amount/balance), then the
    // payment + allocation INSERT, then the GUARDED optimistic-lock invoice
    // update (`lock_version = expected` — the DB guard, never a JS check). A
    // LOCK_CONFLICT rolls the whole transaction back (no orphan payment/
    // allocation). The payment/allocation/invoice update commit together.
    capturePayment: (input) => {
      const existing = paymentStore.find(
        (p) => p.tenantId === input.tenantId && p.idempotencyKey === input.idempotencyKey);
      if (existing !== undefined) {
        const invoice = invoiceStore.find((i) => i.id === input.invoiceId);
        return {
          ok: true,
          replayed: true,
          payment: {
            paymentId: existing.id, status: existing.status, amountMinor: existing.amountMinor,
            method: existing.method, replayed: true,
            invoice: invoice === undefined
              ? { id: input.invoiceId, invoiceNumber: '', status: '', totalMinor: 0, paidMinor: 0 }
              : { id: invoice.id, invoiceNumber: invoice.invoiceNumber, status: invoice.status,
                  totalMinor: invoice.totalMinor, paidMinor: invoice.paidMinor },
          },
        };
      }

      const invoice = invoiceStore.find((i) => i.id === input.invoiceId);
      if (invoice === undefined) return { ok: false, reason: 'INVOICE_NOT_FOUND' };
      if (invoice.status === 'voided') return { ok: false, reason: 'VOIDED' };
      if (invoice.paidMinor >= invoice.totalMinor) return { ok: false, reason: 'ALREADY_PAID' };
      if (input.amountMinor <= 0) return { ok: false, reason: 'AMOUNT_INVALID' };
      const remaining = invoice.totalMinor - invoice.paidMinor;
      if (input.amountMinor > remaining) {
        return { ok: false, reason: 'EXCEEDS_BALANCE', amountMinor: input.amountMinor, remaining };
      }
      if (invoice.lockVersion !== input.expectedLockVersion) return { ok: false, reason: 'LOCK_CONFLICT' };

      // COMMIT — payment + allocation + guarded invoice update land together.
      const payment = {
        id: 'aaaaaaaa-0000-4000-8000-000000000i' + String(paymentStore.length + 1).padStart(2, '0'),
        tenantId: input.tenantId, facilityId: input.facilityId, patientId: input.patientId,
        method: input.method, providerRef: input.providerRef, amountMinor: input.amountMinor,
        currency: 'NPR', status: 'captured', idempotencyKey: input.idempotencyKey,
        receivedBy: input.receivedBy, receivedAt: '2026-03-02T12:00:00Z',
      };
      paymentStore.push(payment);
      allocationStore.push({
        id: 'aaaaaaaa-0000-4000-8000-000000000j' + String(allocationStore.length + 1).padStart(2, '0'),
        tenantId: input.tenantId, paymentId: payment.id, invoiceId: input.invoiceId,
        amountMinor: input.amountMinor, allocatedAt: '2026-03-02T12:00:00Z', createdBy: input.receivedBy,
      });
      invoice.paidMinor += input.amountMinor;
      invoice.status = invoice.paidMinor >= invoice.totalMinor ? 'paid' : 'partially_paid';
      invoice.lockVersion += 1;
      return {
        ok: true,
        replayed: false,
        payment: {
          paymentId: payment.id, status: payment.status, amountMinor: payment.amountMinor,
          method: payment.method, replayed: false,
          invoice: { id: invoice.id, invoiceNumber: invoice.invoiceNumber, status: invoice.status,
                      totalMinor: invoice.totalMinor, paidMinor: invoice.paidMinor },
        },
      };
    },

    // --- invoices:show wiring (Phase 17) -----------------------------

    // Simulated RLS-scoped single-invoice read (mirror of the deployed
    // wiring + BillingController::showInvoice): out-of-scope ≡ nonexistent
    // → null → 404. Returns the presentable header + lines ordered by
    // line_no (the exact presentInvoice shape; the Laravel show contract
    // carries NO payments/allocations).
    showInvoice: (claims, id) => {
      const invoice = invoiceStore.find((i) => i.id === id);
      if (invoice === undefined) return null;
      if (invoice.tenantId !== claims.app_tenant_id || invoice.facilityId !== claims.app_facility_id) return null;
      const lines = invoiceLineStore
        .filter((l) => l.invoiceId === id)
        .sort((a, b) => a.lineNo - b.lineNo)
        // Present ONLY the approved line fields (presentInvoice parity) —
        // tenant/charge ids never leave the store.
        .map(({ id, description, amountMinor, taxMinor }) => ({ id, description, amountMinor, taxMinor }));
      const { tenantId, ...header } = invoice;
      return { invoice: { ...header, issuedAt: header.issuedAt ?? null }, lines };
    },

    // --- invoices:payments wiring (Phase 18) -------------------------

    // Simulated RLS-scoped payments read (mirror of the deployed wiring +
    // BillingController::payments): the invoice id is a resource selector —
    // out-of-scope ≡ nonexistent → null → 404. Allocations are read under
    // the same claims (payment_allocations TENANT_ONLY) and ordered by
    // allocated_at ascending; each payment's method is resolved under the
    // caller's tenant + facility (payments TENANT_FACILITY) — an
    // out-of-scope/missing payment renders `method: null`
    // (`payment?->method` parity). No mutation, no audit (the Laravel read
    // audits nothing).
    listInvoicePayments: (claims, id) => {
      const invoice = invoiceStore.find((i) => i.id === id);
      if (invoice === undefined) return null;
      if (invoice.tenantId !== claims.app_tenant_id || invoice.facilityId !== claims.app_facility_id) return null;
      return allocationStore
        .filter((a) => a.invoiceId === id && a.tenantId === claims.app_tenant_id)
        .sort((a, b) => String(a.allocatedAt).localeCompare(String(b.allocatedAt)))
        .map((a) => {
          const payment = paymentStore.find(
            (p) => p.id === a.paymentId && p.tenantId === claims.app_tenant_id && p.facilityId === claims.app_facility_id,
          );
          return { paymentId: a.paymentId, method: payment ? payment.method : null, amountMinor: a.amountMinor, allocatedAt: a.allocatedAt };
        });
    },

    // --- encounters:charges wiring (Phase 19) -------------------------

    // Simulated RLS-scoped charges read (mirror of the deployed wiring +
    // EncounterController::charges): the encounter id is a resource
    // selector — out-of-scope ≡ nonexistent → null → 404. Charges are read
    // under the same claims (charges is TENANT_FACILITY) bound to the
    // verified encounter and ordered by charged_at ascending
    // (`->orderBy('charged_at')`, NULLS LAST like Postgres); ALL statuses
    // return — including voided (the Laravel hasMany has no status filter).
    // No mutation, no audit (the Laravel read audits nothing).
    listEncounterCharges: (claims, id) => {
      const encounter = encounterStore.find((e) => e.id === id);
      if (encounter === undefined) return null;
      if (encounter.tenantId !== claims.app_tenant_id || encounter.facilityId !== claims.app_facility_id) return null;
      return chargeStore
        .filter((c) => c.encounterId === id && c.tenantId === claims.app_tenant_id && c.facilityId === claims.app_facility_id)
        .sort((a, b) => {
          if (a.chargedAt === b.chargedAt) return 0;
          if (a.chargedAt === undefined || a.chargedAt === null) return 1;
          if (b.chargedAt === undefined || b.chargedAt === null) return -1;
          return String(a.chargedAt).localeCompare(String(b.chargedAt));
        })
        .map(({ id, sourceType, description, amountMinor, currency, status, chargedAt }) => ({
          id, sourceType, description, amountMinor, currency, status,
          chargedAt: chargedAt ?? null,
        }));
    },

    // --- encounters:show wiring (Phase 20) --------------------------

    // Simulated RLS-scoped single-encounter read (mirror of the deployed
    // wiring + EncounterController::show): out-of-scope ≡ nonexistent →
    // null → 404. Returns the presentable header only (present() parity —
    // the Laravel show contract carries NO related data). No mutation.
    showEncounter: (claims, id) => {
      const encounter = encounterStore.find((e) => e.id === id);
      if (encounter === undefined) return null;
      if (encounter.tenantId !== claims.app_tenant_id || encounter.facilityId !== claims.app_facility_id) return null;
      // Present ONLY the approved header fields (present() parity) —
      // tenant/internal fields never leave the store.
      const { tenantId, ...row } = encounter;
      return {
        id: row.id, facilityId: row.facilityId, patientId: row.patientId,
        appointmentId: row.appointmentId ?? null, providerStaffId: row.providerStaffId,
        type: row.type, status: row.status, startedAt: row.startedAt ?? null,
        endedAt: row.endedAt ?? null, signedAt: row.signedAt ?? null, lockVersion: row.lockVersion,
      };
    },

    // Test introspection (not part of the function contract).
    getAppointments: () => appointmentStore,
    getAuditEvents: () => auditEvents,
    getEncounters: () => encounterStore,
    getNotes: () => noteStore,
    getCharges: () => chargeStore,
    getInvoices: () => invoiceStore,
    getInvoiceLines: () => invoiceLineStore,
    getPayments: () => paymentStore,
    getAllocations: () => allocationStore,
    getTimeline: () => timelineStore,
    getIdentifiers: () => identifierStore,
    getContacts: () => contactStore,
    getPolicies: () => policyStore,
    getPayers: () => payerStore,
    getConsents: () => consentStore,
    getDocuments: () => documentStore,
    getDepartments: () => departmentStore,
    getBranches: () => branchStore,
    getLocations: () => locationStore,
    getWards: () => wardStore,
    getRooms: () => roomStore,
    getBeds: () => bedStore,
    getStaff: () => staffStore,
    getServices: () => serviceStore,
    getMedications: () => medicationStore,
    getScheduleTemplates: () => scheduleTemplateStore,
    getScheduleExceptions: () => scheduleExceptionStore,
    getSettings: () => settingStore,
    ...overrides,
  };
}

function gotrueToken(payload = {}, overrides = {}) {
  // The realistic GoTrue access-token claim set (CHECKPOINT 7): standard
  // claims + the authenticated role/email/session_id claims GoTrue emits.
  // `sub` is the auth.users UUID; the application id NEVER appears here.
  return signJwt({
    iss: ISSUER,
    sub: SUB_TENANT_ADMIN,
    aud: AUDIENCE,
    exp: NOW + 3600,
    iat: NOW,
    jti: 'gotrue-jti-' + Math.random().toString(36).slice(2),
    email: 'admin@a.test',
    phone: '',
    role: 'authenticated',
    session_id: 'session-123',
    ...payload,
  }, overrides.secret ?? SECRET);
}

function req(headers = {}, url = 'https://example.supabase.co/functions/v1/health-auth') {
  return new Request(url, { headers: { ...headers } });
}

async function bodyJson(response) {
  return JSON.parse(await response.text());
}

/* ------------------------------------------------------------------ */
/* 1. JWT verification                                                 */
/* ------------------------------------------------------------------ */

await test('rejects a missing Authorization header with 401 INVALID_TOKEN', async () => {
  const deps = makeDeps();
  const response = await handleHealthAuth(req(), deps);
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.ok(body.error.correlationId);
  assert.ok(response.headers.get('X-Correlation-Id'));
});

await test('rejects a malformed token with 401', async () => {
  const response = await handleHealthAuth(req({ Authorization: 'Bearer not-a-jwt' }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('rejects a token with an invalid signature', async () => {
  const token = await gotrueToken({}, { secret: 'a-different-secret' });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('rejects an expired token with 401 TOKEN_EXPIRED', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_TENANT_ADMIN,
  }, SECRET);
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('rejects a token with the wrong audience', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: 'service_role', iat: NOW, exp: NOW + 3600, sub: SUB_TENANT_ADMIN,
  }, SECRET);
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('rejects a token with a not-yet-valid nbf (realistic GoTrue shape)', async () => {
  const token = await gotrueToken({ nbf: NOW + 3600 });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('accepts the realistic GoTrue claim set (jti/session_id/role/email are tolerated, never trusted)', async () => {
  // GoTrue tokens carry more claims than ours; the verifier must accept the
  // real shape and the handler must ignore everything except `sub`.
  const token = await gotrueToken();
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.ok(!body.data.email && !body.data.role, 'GoTrue claims must not echo into the response');
});

await test('rejects alg=none tokens (algorithm pinning)', async () => {
  // Hand-crafted header with alg:none, unsigned.
  const b64 = (data) => btoa(JSON.stringify(data)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  const token = `${b64({ alg: 'none', typ: 'JWT' })}.${b64({ sub: SUB_TENANT_ADMIN })}.`;
  await assert.rejects(() => verifyJwt(token, { secret: SECRET, issuer: ISSUER, audience: AUDIENCE }), JwtError);
});

await test('rejects a token whose subject has no application account', async () => {
  const token = await gotrueToken({ sub: SUB_UNKNOWN });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

/* ------------------------------------------------------------------ */
/* 2. Identity / context                                               */
/* ------------------------------------------------------------------ */

await test('maps a valid subject to the correct application user and resolves context', async () => {
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.authenticated, true);
  assert.equal(body.data.userResolved, true);
  assert.equal(body.data.contextResolved, true);
  assert.ok(typeof body.data.correlationId === 'string' && body.data.correlationId.length > 0);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, null);
  assert.equal(body.meta.claimsIssued, true);
});

await test('rejects a locked identity with 403 FORBIDDEN', async () => {
  const token = await gotrueToken({ sub: SUB_LOCKED });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('rejects a disabled identity with 403 FORBIDDEN', async () => {
  const token = await gotrueToken({ sub: SUB_DISABLED });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('rejects a suspended organization with 403 TENANT_SUSPENDED', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended', facilityId: null,
      branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => suspendedOrg,
  });
  const token = await gotrueToken({ sub: SUB_SUSPENDED });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('facility proposal cannot escape the assignment (org-scoped admin has none)', async () => {
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('facility proposal cannot escape to another facility or tenant', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  for (const proposal of ['fac-a2', 'fac-b']) {
    const response = await handleHealthAuth(
      req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': proposal }),
      makeDeps(),
    );
    assert.equal(response.status, 403, `proposal ${proposal} must be denied`);
    assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
  }
});

await test('a valid facility proposal resolves and a branch cannot escape it', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });

  const ok = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  assert.equal(ok.status, 200);
  assert.equal((await bodyJson(ok)).meta.context.facilityId, 'fac-a1');

  // Branch in the same facility → ok; another branch → denied.
  const branchOk = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-a1' }),
    makeDeps(),
  );
  assert.equal((await bodyJson(branchOk)).meta.context.branchId, 'br-a1');

  const branchEsc = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-a2' }),
    makeDeps(),
  );
  assert.equal(branchEsc.status, 403);
  assert.equal((await bodyJson(branchEsc)).error.code, 'BRANCH_DENIED');

  const branchCrossTenant = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b' }),
    makeDeps(),
  );
  assert.equal(branchCrossTenant.status, 403);
  assert.equal((await bodyJson(branchCrossTenant)).error.code, 'BRANCH_DENIED');
});

await test('forged tenant/facility/branch claims in the token payload cannot alter authoritative claims', async () => {
  // A hostile token that ALSO claims a different tenant / platform status in
  // its app_* payload claims. The handler ignores those: it resolves the
  // context from the identity's active assignments and echoes server truth.
  const token = await gotrueToken({
    sub: SUB_TENANT_ADMIN,
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_branch_id: 'br-b',
    app_is_platform: 'true',
  });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  // The context echo is the server-derived truth — the forged values never
  // surface, and platform status cannot be claimed by a tenant user.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, null);
  assert.equal(body.meta.context.branchId, null);

  // A forged facility PROPOSAL header is still validated as a proposal — and
  // correctly denied for an org-scoped admin with no facility assignment.
  const forgedHeader = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-b' }),
    makeDeps(),
  );
  assert.equal(forgedHeader.status, 403);
  assert.equal((await bodyJson(forgedHeader)).error.code, 'FACILITY_DENIED');
});

await test('support context resolves to the session target and cannot become platform or tenant context', async () => {
  const token = await gotrueToken({ sub: SUB_PLATFORM });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');

  // The support context is NOT a normal tenant context: its assignments are
  // the synthesized support_agent role only.
  const context = resolveContext({
    user: users.platformAgent,
    assignments: assignments['u-platform'],
    isPlatformRoute: false,
    proposals: {},
    activeSupportSession: sessions.get('u-platform'),
    deps: makeDeps(),
  });
  assert.equal(context.kind, 'support');
  assert.equal(context.isPlatform, false);
  assert.equal(context.assignments.length, 1);
  assert.equal(context.assignments[0].role?.code, 'support_agent');
  assert.equal(can(context, 'platform:admin'), false);
  assert.equal(can(context, 'organization:manage'), false);
  assert.equal(can(context, 'patient:view'), true);
});

await test('platform context cannot reach tenant business permissions', async () => {
  const context = resolveContext({
    user: users.platformAgent,
    assignments: assignments['u-platform'],
    isPlatformRoute: true,
    proposals: {},
    activeSupportSession: null,
    deps: makeDeps(),
  });
  assert.equal(context.kind, 'platform');
  assert.equal(context.isPlatform, true);
  assert.equal(can(context, 'support:manage'), true);
  assert.equal(can(context, 'platform:admin'), true);
  // tenant-scoped permissions are unreachable in platform context
  assert.equal(can(context, 'patient:view'), false);
});

await test('tenant context grants facility-scoped permissions only in the assigned facility', async () => {
  const doctorContext = resolveContext({
    user: users.facilityDoctor,
    assignments: assignments['u-fac-doctor'],
    isPlatformRoute: false,
    proposals: { facilityId: 'fac-a1' },
    activeSupportSession: null,
    deps: makeDeps(),
  });
  assert.equal(can(doctorContext, 'encounter:document'), true);
  assert.equal(can(doctorContext, 'organization:manage'), false);
});

/* ------------------------------------------------------------------ */
/* 3. Claims construction                                              */
/* ------------------------------------------------------------------ */

await test('claimsFromContext emits exactly the five app_* keys with server-derived values', () => {
  const context = resolveContext({
    user: users.facilityDoctor,
    assignments: assignments['u-fac-doctor'],
    isPlatformRoute: false,
    proposals: { facilityId: 'fac-a1', branchId: 'br-a1' },
    activeSupportSession: null,
    deps: makeDeps(),
  });
  const claims = claimsFromContext(context);

  assert.deepEqual(Object.keys(claims), CLAIM_KEYS);
  assert.equal(claims.app_user_id, 'u-fac-doctor');
  assert.equal(claims.app_tenant_id, 'org-a');
  assert.equal(claims.app_facility_id, 'fac-a1');
  assert.equal(claims.app_branch_id, 'br-a1');
  assert.equal(claims.app_is_platform, 'false');
  assert.equal(claimsComplete(claims), true);
});

await test('missing claims fail closed: normalize yields empty values that RLS treats as zero access', () => {
  const claims = normalizeClaims({ sub: 'x', role: 'authenticated', app_is_platform: 'false' });
  assert.deepEqual(claims, {
    app_user_id: '',
    app_tenant_id: '',
    app_facility_id: '',
    app_branch_id: '',
    app_is_platform: 'false',
  });
  // Empty values are the safe default: the Phase 2 helpers resolve them to
  // NULL and the policies grant zero access (proven against real PostgreSQL
  // by EdgeFunctionPipelineTest). claimsComplete only checks SHAPE.
  assert.equal(claimsComplete(claims), true);
  assert.equal(claimsComplete({ app_user_id: 123 }), false);
});

await test('normalize strips service_role, permissions, and unknown keys', () => {
  const claims = normalizeClaims({
    role: 'service_role',
    permissions: ['*'],
    sub: 'gotrue-id',
    app_tenant_id: 'org-a',
  });
  assert.deepEqual(Object.keys(claims), CLAIM_KEYS);
  assert.equal(claims.app_tenant_id, 'org-a');
  assert.equal('role' in claims, false);
  assert.equal('permissions' in claims, false);
  assert.equal('sub' in claims, false);
});

/* ------------------------------------------------------------------ */
/* 4. Contract envelope                                               */
/* ------------------------------------------------------------------ */

await test('success envelope matches the Laravel contract', async () => {
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  const body = await bodyJson(response);

  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.meta).sort(), ['claimsIssued', 'context']);
  assert.deepEqual(Object.keys(body.meta.context).sort(), ['branchId', 'facilityId', 'tenantId', 'timezone']);
  assert.deepEqual(body.links, {});
  assert.ok(response.headers.get('X-Request-Id'));
  assert.ok(response.headers.get('X-Correlation-Id'));
});

await test('error envelope matches the Laravel contract and carries correlationId', async () => {
  const response = await handleHealthAuth(req(), makeDeps());
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body), ['error']);
  assert.deepEqual(Object.keys(body.error), ['code', 'message', 'correlationId']);
  assert.equal(typeof body.error.correlationId, 'string');
  assert.ok(body.error.correlationId.length > 0);
});

await test('responses never leak secrets, tokens, password hashes, or permissions', async () => {
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const ok = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  const err = await handleHealthAuth(req(), makeDeps());

  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permissions'), 'permissions must not appear');
    assert.ok(!text.includes('service_role'), 'service_role must not appear');
  }
});

await test('correlation id propagates when the client supplies one', async () => {
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Correlation-Id': 'corr-123' }),
    makeDeps(),
  );
  const body = await bodyJson(response);
  assert.equal(body.data.correlationId, 'corr-123');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-123');
  assert.equal(correlationId(req({ 'X-Correlation-Id': 'incoming' })), 'incoming');
});

/* ------------------------------------------------------------------ */
/* 5. Identity mapping (pure)                                          */
/* ------------------------------------------------------------------ */

/* ------------------------------------------------------------------ */
/* 6. `me` / my-context (CHECKPOINT 3–5, pure tier)                    */
/* ------------------------------------------------------------------ */

await test('me returns the safe identity and server-derived context for a valid token', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const response = await handleMe(req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }), makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.me, { id: 'u-fac-doctor', email: 'doc@a.test', status: 'active' });
  assert.deepEqual(body.data.context, {
    kind: 'tenant', organizationId: 'org-a', facilityId: 'fac-a1', branchId: null, supportSessionId: null,
  });
  assert.equal(body.data.claimsIssued, true);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
});

await test('me never exposes permissions, assignments, tokens, or hashes', async () => {
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handleMe(req({ Authorization: `Bearer ${token}` }), makeDeps());
  const text = await response.text();
  assert.ok(!text.includes('permission'), 'permissions must not appear');
  assert.ok(!text.includes('assignments'), 'assignments must not appear');
  assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
  assert.ok(!text.includes(token), 'bearer token must not appear');
  assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
});

await test('me fails closed through the shared pipeline (missing token, locked user, suspended tenant)', async () => {
  // Missing token.
  let response = await handleMe(req(), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');

  // Locked identity.
  response = await handleMe(req({ Authorization: `Bearer ${await gotrueToken({ sub: SUB_LOCKED })}` }), makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');

  // Suspended tenant.
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended', facilityId: null,
      branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => suspendedOrg,
  });
  response = await handleMe(req({ Authorization: `Bearer ${await gotrueToken({ sub: SUB_SUSPENDED })}` }), deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('me reflects only the server-derived context — forged headers and payload claims are inert', async () => {
  // Hostile token claims a different tenant; hostile headers propose fac-b
  // for an org-scoped admin with NO facility assignment.
  const token = await gotrueToken({
    sub: SUB_TENANT_ADMIN,
    app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_is_platform: 'true',
  });
  const response = await handleMe(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-b' }),
    makeDeps(),
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');

  // Without the hostile proposal the server truth is echoed.
  const clean = await handleMe(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(clean.status, 200);
  const body = await bodyJson(clean);
  assert.equal(body.data.context.organizationId, 'org-a');
  assert.equal(body.data.context.kind, 'tenant');
  assert.equal(body.data.me.id, 'u-tenant-admin');
});

/* ------------------------------------------------------------------ */
/* 7. GoTrue refresh/session parity (CHECKPOINT 6 — simulated)          */
/* ------------------------------------------------------------------ */

await test('a refreshed access token (new iat/jti/session_id) verifies and resolves', async () => {
  // Simulates the refresh lifecycle: same identity, freshly minted session.
  const refreshed = await gotrueToken({ sub: SUB_TENANT_ADMIN, jti: 'jti-refreshed', session_id: 'session-2' });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${refreshed}` }), makeDeps());
  assert.equal(response.status, 200);
});

await test('rejects a token with the wrong issuer', async () => {
  const token = await signJwt({
    iss: 'evil-issuer', sub: SUB_TENANT_ADMIN, aud: AUDIENCE, iat: NOW, exp: NOW + 3600,
  }, SECRET);
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('rejects a token missing the sub claim', async () => {
  const token = await signJwt({ iss: ISSUER, aud: AUDIENCE, iat: NOW, exp: NOW + 3600 }, SECRET);
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('rejects a token whose iat is in the future', async () => {
  const token = await gotrueToken({ iat: NOW + 3600 });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('accepts a valid token without a jti (GoTrue variants may omit it)', async () => {
  const token = await signJwt({
    iss: ISSUER, sub: SUB_TENANT_ADMIN, aud: AUDIENCE, iat: NOW, exp: NOW + 3600,
  }, SECRET);
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
});

await test('a previously-valid facility proposal cannot be replayed after the assignment changes', async () => {
  // The doctor was valid in fac-a1; the assignment is revoked (new deps).
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const revokedDeps = makeDeps({ loadActiveAssignments: () => [] });
  const response = await handleHealthAuth(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    revokedDeps,
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('resolveAppUser maps a known subject and refuses empty/unknown ones', () => {
  const find = (sub) => (sub === SUB_TENANT_ADMIN ? users.tenantAdmin : null);
  assert.equal(resolveAppUser(SUB_TENANT_ADMIN, find)?.id, 'u-tenant-admin');
  assert.equal(resolveAppUser('', find), null);
  assert.equal(resolveAppUser(SUB_UNKNOWN, find), null);
});

await test('a non-UUID subject fails closed — the lookup is never even attempted', () => {
  const find = (sub) => { throw new Error('lookup must not run for a malformed subject'); };
  assert.equal(resolveAppUser('not-a-uuid', find), null);
  assert.equal(resolveAppUser('sub-nobody', find), null);
  assert.equal(resolveAppUser('a'.repeat(36), find), null);
});

await test('a token carrying a non-UUID sub is refused with 401 (identity-binding hardening)', async () => {
  const token = await gotrueToken({ sub: 'not-a-uuid' });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('claims carry the APPLICATION user id, never the GoTrue sub (auth.users is never substituted)', async () => {
  // The token's sub is the auth.users UUID; the RLS claims must key off the
  // application user id (u-tenant-admin), NOT the subject.
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handleHealthAuth(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
  const text = await response.text();
  assert.ok(!text.includes(SUB_TENANT_ADMIN), 'the GoTrue sub must never be echoed in the response');
  assert.notEqual(SUB_TENANT_ADMIN, 'u-tenant-admin');

  const context = resolveContext({
    user: users.tenantAdmin,
    assignments: assignments['u-tenant-admin'],
    isPlatformRoute: false,
    proposals: {},
    activeSupportSession: null,
    deps: makeDeps(),
  });
  const claims = claimsFromContext(context);
  assert.equal(claims.app_user_id, 'u-tenant-admin');
  assert.notEqual(claims.app_user_id, SUB_TENANT_ADMIN);
  assert.ok(!JSON.stringify(claims).includes(SUB_TENANT_ADMIN), 'the GoTrue sub must not appear in the claims');
});

/* ------------------------------------------------------------------ */
/* 8. `patients:list` (CHECKPOINT 3–6, pure tier)                      */
/* ------------------------------------------------------------------ */

const PATIENT_ROW_KEYS = [
  'id', 'mrn', 'facilityId', 'fullName', 'dateOfBirth', 'sex', 'bloodGroup', 'status', 'createdAt', 'updatedAt',
];

await test('patients:list returns exactly the rows visible to the resolved facility context', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const response = await handlePatientsList(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 3);
  assert.deepEqual(
    body.data.map((p) => p.id).sort(),
    ['aaaaaaaa-0000-4000-8000-000000000101', 'aaaaaaaa-0000-4000-8000-000000000102', 'aaaaaaaa-0000-4000-8000-000000000103'],
  );
  assert.equal(body.meta.count, 3);
  assert.equal(body.meta.claimsIssued, true);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
});

await test('patients:list exposes only the approved fields (no raw PHI beyond the established API)', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const response = await handlePatientsList(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  const body = await bodyJson(response);
  for (const row of body.data) {
    assert.deepEqual(Object.keys(row).sort(), [...PATIENT_ROW_KEYS].sort());
  }
});

await test('patients:list never leaks secrets, tokens, permissions, or assignments', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const ok = await handlePatientsList(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  const err = await handlePatientsList(req(), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignments'), 'assignments must not appear');
    assert.ok(!text.includes('service_role'), 'service_role must not appear');
  }
});

await test('patients:list fails closed on every authentication class', async () => {
  const deps = makeDeps();
  const cases = [
    ['missing token', {}, 401, 'INVALID_TOKEN'],
    ['unknown subject', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_UNKNOWN })}` }, 401, 'INVALID_TOKEN'],
    ['locked user', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_LOCKED })}` }, 403, 'FORBIDDEN'],
    ['disabled user', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_DISABLED })}` }, 403, 'FORBIDDEN'],
  ];
  for (const [name, headers, status, code] of cases) {
    const response = await handlePatientsList(req(headers), deps);
    assert.equal(response.status, status, name);
    assert.equal((await bodyJson(response)).error.code, code, name);
  }
});

await test('patients:list denies a suspended tenant with TENANT_SUSPENDED', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended', facilityId: null,
      branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => suspendedOrg,
  });
  const response = await handlePatientsList(
    req({ Authorization: `Bearer ${await gotrueToken({ sub: SUB_SUSPENDED })}` }),
    deps,
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:list denies a missing capability with 403 SCOPE_DENIED', async () => {
  const token = await gotrueToken({ sub: SUB_NO_PERM });
  const response = await handlePatientsList(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('patients:list denies platform context without a support session (patient:view is tenant-scoped)', async () => {
  const deps = makeDeps({
    activeSupportSession: () => null,
    isPlatformRoute: () => true,
  });
  const response = await handlePatientsList(
    req({ Authorization: `Bearer ${await gotrueToken({ sub: SUB_PLATFORM })}` }),
    deps,
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('patients:list returns an empty set when the claims grant no facility scope', async () => {
  // An org-scoped admin has NO facility claim; the tenant+facility RLS
  // policy therefore exposes zero patient rows (facility_id = NULL never
  // matches) — fail closed, documented behavior.
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN });
  const response = await handlePatientsList(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
  assert.equal(body.meta.count, 0);
});

await test('patients:list ignores forged app_* claims and forged facility proposals', async () => {
  // Hostile token claims tenant B + platform; hostile header proposes fac-b.
  const token = await gotrueToken({
    sub: SUB_FAC_DOCTOR,
    app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_is_platform: 'true',
  });
  const forged = await handlePatientsList(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-b' }),
    makeDeps(),
  );
  assert.equal(forged.status, 403);
  assert.equal((await bodyJson(forged)).error.code, 'FACILITY_DENIED');

  // Without the hostile proposal, the server truth is a1 — never fac-b.
  const clean = await handlePatientsList(req({ Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(clean.status, 200);
  const body = await bodyJson(clean);
  assert.deepEqual(
    body.data.map((p) => p.id).sort(),
    ['aaaaaaaa-0000-4000-8000-000000000101', 'aaaaaaaa-0000-4000-8000-000000000102', 'aaaaaaaa-0000-4000-8000-000000000103'],
  );
  assert.equal(body.meta.context.facilityId, 'fac-a1');
});

await test('patients:list envelope matches the existing contract (data/meta/links + correlation ids)', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const response = await handlePatientsList(
    req({ Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1', 'X-Correlation-Id': 'corr-pat' }),
    makeDeps(),
  );
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.meta).sort(), ['claimsIssued', 'context', 'count']);
  assert.deepEqual(body.links, {});
  assert.equal(response.headers.get('X-Request-Id'), 'corr-pat');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-pat');
});

/* ------------------------------------------------------------------ */
/* 9. `patients:show` (CHECKPOINT 3–6, pure tier)                      */
/* ------------------------------------------------------------------ */

function showReq(id, headers = {}) {
  return req(headers, `https://example.supabase.co/functions/v1/patients-show/${id}`);
}

async function showAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsShow(showReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }), deps);
}

await test('patients:show reads an authorized patient (200, exact approved fields)', async () => {
  const response = await showAs(SUB_FAC_DOCTOR, 'aaaaaaaa-0000-4000-8000-000000000101', { 'X-Swasthya-Facility': 'fac-a1' });
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.id, 'aaaaaaaa-0000-4000-8000-000000000101');
  assert.equal(body.data.mrn, 'MRN-A1-001');
  assert.equal(body.data.fullName, 'Aarav Shrestha');
  assert.deepEqual(Object.keys(body.data).sort(), [...PATIENT_ROW_KEYS].sort());
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:show tolerates a patient with null optional fields', async () => {
  const response = await showAs(SUB_FAC_DOCTOR, 'aaaaaaaa-0000-4000-8000-000000000103', { 'X-Swasthya-Facility': 'fac-a1' });
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.dateOfBirth, null);
  assert.equal(body.data.sex, null);
  assert.equal(body.data.bloodGroup, null);
});

await test('patients:show fails closed on every authentication class', async () => {
  const deps = makeDeps();
  const cases = [
    ['missing token', showReq('aaaaaaaa-0000-4000-8000-000000000101'), 401, 'INVALID_TOKEN'],
    ['invalid signature', showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${await gotrueToken({}, { secret: 'x' })}` }), 401, 'INVALID_TOKEN'],
    ['unknown subject', showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_UNKNOWN })}` }), 401, 'INVALID_TOKEN'],
    ['locked user', showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_LOCKED })}` }), 403, 'FORBIDDEN'],
    ['disabled user', showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_DISABLED })}` }), 403, 'FORBIDDEN'],
  ];
  for (const [name, request, status, code] of cases) {
    const response = await handlePatientsShow(request, deps);
    assert.equal(response.status, status, name);
    assert.equal((await bodyJson(response)).error.code, code, name);
  }
});

await test('patients:show denies a suspended tenant with TENANT_SUSPENDED', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended', facilityId: null,
      branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => suspendedOrg,
  });
  const response = await showAs(SUB_SUSPENDED, 'aaaaaaaa-0000-4000-8000-000000000101', {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:show denies a missing capability with 403 SCOPE_DENIED before any lookup', async () => {
  const response = await showAs(SUB_NO_PERM, 'aaaaaaaa-0000-4000-8000-000000000101', { 'X-Swasthya-Facility': 'fac-a1' });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('patients:show refuses a principal with no active assignments (missing context)', async () => {
  const deps = makeDeps({ loadActiveAssignments: () => [] });
  const response = await handlePatientsShow(
    showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_FAC_DOCTOR })}` }),
    deps,
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:show treats forged app_* claims as inert', async () => {
  const token = await gotrueToken({
    sub: SUB_FAC_DOCTOR,
    app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_is_platform: 'true',
  });
  const response = await handlePatientsShow(
    showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  assert.equal(response.status, 200);
  assert.equal((await bodyJson(response)).data.id, 'aaaaaaaa-0000-4000-8000-000000000101');
});

await test('patients:show rejects forged facility and branch proposals', async () => {
  const facility = await showAs(SUB_FAC_DOCTOR, 'aaaaaaaa-0000-4000-8000-000000000101', { 'X-Swasthya-Facility': 'fac-b' });
  assert.equal(facility.status, 403);
  assert.equal((await bodyJson(facility)).error.code, 'FACILITY_DENIED');

  const branch = await showAs(SUB_FAC_DOCTOR, 'aaaaaaaa-0000-4000-8000-000000000101', {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  });
  assert.equal(branch.status, 403);
  assert.equal((await bodyJson(branch)).error.code, 'BRANCH_DENIED');
});

await test('patients:show maps an out-of-scope patient to 404 (existence never leaked)', async () => {
  // pat-a2-1 lives in fac-a2 — the fac-a1 doctor must get 404, not 403.
  const outOfScope = await showAs(SUB_FAC_DOCTOR, 'aaaaaaaa-0000-4000-8000-000000000201', { 'X-Swasthya-Facility': 'fac-a1' });
  assert.equal(outOfScope.status, 404);
  assert.equal((await bodyJson(outOfScope)).error.code, 'NOT_FOUND');
});

await test('patients:show maps a nonexistent patient to 404 — indistinguishable from out-of-scope', async () => {
  const missing = await showAs(SUB_FAC_DOCTOR, 'ffffffff-0000-4000-8000-000000000000', { 'X-Swasthya-Facility': 'fac-a1' });
  const outOfScope = await showAs(SUB_FAC_DOCTOR, 'aaaaaaaa-0000-4000-8000-000000000201', { 'X-Swasthya-Facility': 'fac-a1' });
  assert.equal(missing.status, 404);
  assert.equal(outOfScope.status, 404);
  // Identical bodies — no way to tell a foreign record from a missing one.
  const missingBody = await bodyJson(missing);
  const outOfScopeBody = await bodyJson(outOfScope);
  assert.equal(missingBody.error.code, outOfScopeBody.error.code);
  assert.equal(missingBody.error.message, outOfScopeBody.error.message);
});

await test('patients:show maps a malformed patient id to 404 (Laravel binding parity)', async () => {
  for (const bad of ['not-a-uuid', 'abc', '']) {
    const response = await handlePatientsShow(
      showReq(bad, { Authorization: `Bearer ${await gotrueToken({ sub: SUB_FAC_DOCTOR })}`, 'X-Swasthya-Facility': 'fac-a1' }),
      makeDeps(),
    );
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('patients:show never leaks secrets, tokens, permissions, assignments, or audit data', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const ok = await handlePatientsShow(
    showReq('aaaaaaaa-0000-4000-8000-000000000101', { Authorization: `Bearer ${token}`, 'X-Swasthya-Facility': 'fac-a1' }),
    makeDeps(),
  );
  const err = await handlePatientsShow(showReq('aaaaaaaa-0000-4000-8000-000000000101'), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit data must not appear');
  }
});

await test('patients:show envelope and correlation-id contract', async () => {
  const response = await handlePatientsShow(
    showReq('aaaaaaaa-0000-4000-8000-000000000101', {
      Authorization: `Bearer ${await gotrueToken({ sub: SUB_FAC_DOCTOR })}`,
      'X-Swasthya-Facility': 'fac-a1',
      'X-Correlation-Id': 'corr-show',
    }),
    makeDeps(),
  );
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.meta).sort(), ['claimsIssued', 'context']);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-show');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-show');
});

/* ------------------------------------------------------------------ */
/* 11. appointments:create (Phase 9 — first write endpoint)            */
/* ------------------------------------------------------------------ */

const STAFF_A1 = 'aaaaaaaa-0000-4000-8000-000000000401'; // fac-a1 doctor
const STAFF_A1_EXCEPTION = 'aaaaaaaa-0000-4000-8000-000000000402'; // leave that day
const STAFF_A2 = 'aaaaaaaa-0000-4000-8000-000000000501'; // fac-a2 doctor
const STAFF_B1 = 'aaaaaaaa-0000-4000-8000-000000000601'; // org-b / fac-b doctor
const PAT_A1 = 'aaaaaaaa-0000-4000-8000-000000000101';
const PAT_A2 = 'aaaaaaaa-0000-4000-8000-000000000201';
const PAT_B1 = 'aaaaaaaa-0000-4000-8000-000000000301';

function bookReq(body, headers = {}) {
  return new Request('https://example.supabase.co/functions/v1/appointments-create', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...headers },
    body: typeof body === 'string' ? body : JSON.stringify(body),
  });
}

async function bookAs(sub, body, headers = {}, deps = makeDeps()) {
  return handleAppointmentsCreate(
    bookReq(body, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const BOOK = {
  patientId: PAT_A1,
  providerStaffId: STAFF_A1,
  startsAt: '2026-03-02T09:00:00Z',
  endsAt: '2026-03-02T09:30:00Z',
  appointmentType: 'opd',
  source: 'counter',
};

const APPOINTMENT_KEYS = [
  'id', 'facilityId', 'patientId', 'patient', 'providerStaffId', 'provider',
  'serviceId', 'appointmentType', 'startsAt', 'endsAt', 'status', 'tokenNo',
  'source', 'cancelReason', 'lockVersion',
];

await test('appointments:create books a valid slot (201) with the exact presentation contract', async () => {
  const deps = makeDeps();
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, {}, deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), APPOINTMENT_KEYS.slice().sort());
  assert.equal(body.data.status, 'booked');
  assert.equal(body.data.facilityId, 'fac-a1');
  assert.equal(body.data.patient.fullName, 'Aarav Shrestha');
  assert.equal(body.data.provider.fullName, 'Dr. Kiran Adhikari');
  assert.equal(body.data.lockVersion, 0);
  assert.deepEqual(Object.keys(body.meta).sort(), ['claimsIssued', 'context']);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(deps.getAppointments().length, 1);
});

await test('appointments:create rejects unauthenticated requests (401)', async () => {
  const response = await handleAppointmentsCreate(bookReq(BOOK), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:create rejects a malformed token (401)', async () => {
  const response = await handleAppointmentsCreate(bookReq(BOOK, { Authorization: 'Bearer not-a-jwt' }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:create rejects an expired token (401 TOKEN_EXPIRED)', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_RECEPTIONIST,
  }, SECRET);
  const response = await handleAppointmentsCreate(bookReq(BOOK, { Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('appointments:create rejects an unknown subject (401)', async () => {
  const response = await bookAs(SUB_UNKNOWN, BOOK);
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:create rejects a locked user (403 FORBIDDEN)', async () => {
  const response = await bookAs(SUB_LOCKED, BOOK);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:create rejects a disabled user (403 FORBIDDEN)', async () => {
  const response = await bookAs(SUB_DISABLED, BOOK);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:create rejects a suspended tenant (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await bookAs(SUB_SUSPENDED, BOOK, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('appointments:create fails closed with no context (no assignment)', async () => {
  // Default deny: no active assignments → the shared pipeline resolves no
  // tenant context → 403 FORBIDDEN before any domain logic runs.
  const response = await bookAs(SUB_NO_ASSIGNMENT, BOOK);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:create denies an unauthorized capability (403 SCOPE_DENIED)', async () => {
  const response = await bookAs(SUB_NO_PERM, BOOK);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('appointments:create rejects malformed JSON (400 INVALID_REQUEST)', async () => {
  const response = await handleAppointmentsCreate(
    bookReq('{not json', { Authorization: `Bearer ${await gotrueToken({ sub: SUB_RECEPTIONIST })}` }),
    makeDeps(),
  );
  assert.equal(response.status, 400);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_REQUEST');
});

await test('appointments:create validates required fields (422 with details)', async () => {
  const { patientId, ...missingPatient } = BOOK;
  const response = await bookAs(SUB_RECEPTIONIST, missingPatient);
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.ok(Array.isArray(body.error.details));
  const patient = body.error.details.find((d) => d.field === 'patientId');
  assert.equal(patient.code, 'REQUIRED');
});

await test('appointments:create rejects endsAt not after startsAt (422)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, endsAt: '2026-03-02T09:00:00Z' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details.find((d) => d.field === 'endsAt').code, 'VALIDATION_ERROR');
});

await test('appointments:create rejects a malformed date (422)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, startsAt: 'not-a-date' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details.find((d) => d.field === 'startsAt').code, 'INVALID_FORMAT');
});

await test('appointments:create rejects a non-UUID patient id (422)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, patientId: 'not-a-uuid' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details.find((d) => d.field === 'patientId').code, 'INVALID_FORMAT');
});

await test('appointments:create rejects a non-UUID provider id (422)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, providerStaffId: 'nope' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details.find((d) => d.field === 'providerStaffId').code, 'INVALID_FORMAT');
});

await test('appointments:create rejects unknown body fields (422)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, tenantId: 'org-a' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.ok(body.error.details.some((d) => d.field === 'tenantId'));
});

await test('appointments:create 404s a nonexistent patient (valid UUID)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, patientId: 'ffffffff-0000-4000-8000-000000000000' });
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:create 404s an out-of-scope patient (existence never leaked)', async () => {
  // PAT_A2 lives in fac-a2 — the fac-a1 receptionist must get 404, not 403.
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, patientId: PAT_A2 });
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:create 404s a nonexistent provider', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, providerStaffId: 'ffffffff-0000-4000-8000-000000000000' });
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:create 404s an out-of-scope provider', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, providerStaffId: STAFF_A2 });
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:create rejects a slot under a schedule exception (409)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, providerStaffId: STAFF_A1_EXCEPTION });
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.ok(body.error.message.includes('not available'));
});

await test('appointments:create rejects a booking off the availability grid (409)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, {
    ...BOOK, startsAt: '2026-03-02T09:05:00Z', endsAt: '2026-03-02T09:35:00Z',
  });
  assert.equal(response.status, 409);
  assert.equal((await bodyJson(response)).error.code, 'CONFLICT');
});

await test('appointments:create rejects a provider with no schedule that day (409)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, providerStaffId: STAFF_A1 });
  const dayAfter = await bookAs(SUB_RECEPTIONIST, {
    ...BOOK, startsAt: '2026-03-03T09:00:00Z', endsAt: '2026-03-03T09:30:00Z',
  });
  assert.equal(response.status, 201);
  assert.equal(dayAfter.status, 409);
  assert.equal((await bodyJson(dayAfter)).error.code, 'CONFLICT');
});

await test('appointments:create — duplicate live booking is rejected by the unique-slot race (second 409)', async () => {
  const deps = makeDeps();
  const first = await bookAs(SUB_RECEPTIONIST, BOOK, {}, deps);
  assert.equal(first.status, 201);
  // The second request on the SAME deps instance simulates the race: both
  // pass availability, the DB unique index rejects the second.
  const second = await bookAs(SUB_RECEPTIONIST, BOOK, {}, deps);
  assert.equal(second.status, 409);
  const body = await bodyJson(second);
  assert.equal(body.error.code, 'CONFLICT');
  assert.ok(body.error.message.includes('just booked'));
  assert.equal(deps.getAppointments().length, 1);
});

await test('appointments:create — a cancelled appointment frees the slot (live-status semantics)', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [{
      tenantId: 'org-a', facilityId: 'fac-a1', providerStaffId: STAFF_A1, startsAt: BOOK.startsAt,
      status: 'cancelled',
    }],
  });
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, {}, deps);
  assert.equal(response.status, 201);
  assert.equal((await bodyJson(response)).data.status, 'booked');
});

await test('appointments:create — a completed appointment frees the slot', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [{
      tenantId: 'org-a', facilityId: 'fac-a1', providerStaffId: STAFF_A1, startsAt: BOOK.startsAt,
      status: 'completed',
    }],
  });
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, {}, deps);
  assert.equal(response.status, 201);
});

await test('appointments:create — forged app_* claims are inert (scope stays authoritative)', async () => {
  // A hostile JWT claiming org-b/fac-b: the pipeline derives claims from
  // server-side context, so the org-a booking still succeeds.
  const token = await gotrueToken({
    sub: SUB_RECEPTIONIST,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleAppointmentsCreate(bookReq(BOOK, { Authorization: `Bearer ${token}` }), makeDeps());
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.facilityId, 'fac-a1');
});

await test('appointments:create — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, { 'X-Swasthya-Facility': 'fac-a2' });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('appointments:create — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('appointments:create — a fac-a2 provider cannot be reached via proposal headers', async () => {
  // Even with both headers set to fac-a2 territory, the receptionist's
  // assignment is fac-a1 → the provider 404s (no cross-facility booking).
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, providerStaffId: STAFF_A2 }, {
    'X-Swasthya-Facility': 'fac-a2', 'X-Swasthya-Branch': 'br-a2',
  });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('appointments:create — a cross-tenant booking is impossible (org-b patient via org-a actor)', async () => {
  const response = await bookAs(SUB_RECEPTIONIST, { ...BOOK, patientId: PAT_B1 });
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:create — transaction failure leaves no partial appointment (500)', async () => {
  const deps = makeDeps({ createAppointment: () => ({ ok: false, reason: 'ERROR' }) });
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, {}, deps);
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
  assert.equal(deps.getAppointments().length, 0);
});

await test('appointments:create — audit is attributed to the actor + authoritative context', async () => {
  const deps = makeDeps();
  const response = await bookAs(SUB_RECEPTIONIST, BOOK, { 'X-Correlation-Id': 'corr-book' }, deps);
  assert.equal(response.status, 201);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'appointment.booked');
  assert.equal(events[0].resourceType, 'appointment');
  assert.equal(events[0].resourceId, (await bodyJson(response)).data.id);
  assert.equal(events[0].actorId, 'u-receptionist');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-book');
  assert.deepEqual(events[0].payload, {
    patientId: PAT_A1, providerStaffId: STAFF_A1, startsAt: BOOK.startsAt,
  });
});

await test('appointments:create — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_RECEPTIONIST });
  const ok = await handleAppointmentsCreate(bookReq(BOOK, { Authorization: `Bearer ${token}` }), makeDeps());
  const err = await handleAppointmentsCreate(bookReq(BOOK), makeDeps());
  const conflictDeps = makeDeps();
  await bookAs(SUB_RECEPTIONIST, BOOK, {}, conflictDeps);
  const conflict = await bookAs(SUB_RECEPTIONIST, BOOK, {}, conflictDeps);
  for (const response of [ok, err, conflict]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
  }
});

await test('appointments:create — correlation-id propagation contract', async () => {
  const ok = await bookAs(SUB_RECEPTIONIST, BOOK, { 'X-Correlation-Id': 'corr-book-2' });
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-book-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-book-2');
  // Error responses carry the correlation id in the body envelope too.
  const err = await bookAs(SUB_RECEPTIONIST, { ...BOOK, startsAt: 'not-a-date' }, { 'X-Correlation-Id': 'corr-book-3' });
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-book-3');
});

/* ------------------------------------------------------------------ */
/* 12. appointments:checkin (Phase 10 — second write endpoint)          */
/* ------------------------------------------------------------------ */

const APPT_A1 = 'aaaaaaaa-0000-4000-8000-000000000a01';
const APPT_A2 = 'aaaaaaaa-0000-4000-8000-000000000a02';
const APPT_A2_OTHER_FAC = 'aaaaaaaa-0000-4000-8000-000000000a03';
const APPT_B1 = 'aaaaaaaa-0000-4000-8000-000000000a04';

/** A full AppointmentRow-shaped seed (the shape the check-in presents). */
function fullAppointment(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
    providerStaffId: STAFF_A1, serviceId: null, appointmentType: 'opd',
    startsAt: BOOK.startsAt, endsAt: BOOK.endsAt, status: 'booked',
    tokenNo: null, source: 'counter', cancelReason: null, lockVersion: 0,
    createdAt: '2026-03-02T08:00:00Z', updatedAt: null,
    ...overrides,
  };
}

function checkinReq(id, headers = {}, body = '') {
  return new Request(`https://example.supabase.co/functions/v1/appointments-checkin/${id}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === '' ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function checkinAs(sub, id, headers = {}, body = '', deps = makeDeps()) {
  return handleAppointmentsCheckin(
    checkinReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

await test('appointments:checkin — an eligible booked appointment checks in with the next queue token', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), APPOINTMENT_KEYS.slice().sort());
  assert.equal(body.data.status, 'checked_in');
  assert.equal(body.data.tokenNo, 1);
  assert.equal(body.data.lockVersion, 1);
  assert.equal(body.data.patient.fullName, 'Aarav Shrestha');
  assert.equal(body.data.provider.fullName, 'Dr. Kiran Adhikari');
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  const stored = deps.getAppointments()[0];
  assert.equal(stored.status, 'checked_in');
  assert.equal(stored.tokenNo, 1);
});

await test('appointments:checkin — tokens are sequential per provider and day (TokenIssuer parity)', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [fullAppointment(APPT_A1), fullAppointment(APPT_A2)],
  });
  const first = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', deps);
  const second = await checkinAs(SUB_RECEPTIONIST, APPT_A2, {}, '', deps);
  assert.equal(first.status, 200);
  assert.equal(second.status, 200);
  assert.equal((await bodyJson(first)).data.tokenNo, 1);
  assert.equal((await bodyJson(second)).data.tokenNo, 2);
});

await test('appointments:checkin rejects unauthenticated requests (401)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const response = await handleAppointmentsCheckin(checkinReq(APPT_A1), deps);
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:checkin rejects a malformed token (401)', async () => {
  const response = await handleAppointmentsCheckin(
    checkinReq(APPT_A1, { Authorization: 'Bearer not-a-jwt' }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:checkin rejects an expired token (401 TOKEN_EXPIRED)', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_RECEPTIONIST,
  }, SECRET);
  const response = await handleAppointmentsCheckin(
    checkinReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('appointments:checkin rejects an unknown subject (401)', async () => {
  const response = await checkinAs(SUB_UNKNOWN, APPT_A1);
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:checkin rejects a locked user (403 FORBIDDEN)', async () => {
  const response = await checkinAs(SUB_LOCKED, APPT_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:checkin rejects a disabled user (403 FORBIDDEN)', async () => {
  const response = await checkinAs(SUB_DISABLED, APPT_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:checkin rejects a suspended tenant (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await checkinAs(SUB_SUSPENDED, APPT_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('appointments:checkin denies an unauthorized capability (403 SCOPE_DENIED)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const response = await checkinAs(SUB_NO_PERM, APPT_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  // No mutation happened.
  assert.equal(deps.getAppointments()[0].status, 'booked');
});

await test('appointments:checkin fails closed with no context (no assignment)', async () => {
  const response = await checkinAs(SUB_NO_ASSIGNMENT, APPT_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:checkin — forged app_* claims are inert', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const token = await gotrueToken({
    sub: SUB_RECEPTIONIST,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleAppointmentsCheckin(
    checkinReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  assert.equal((await bodyJson(response)).meta.context.tenantId, 'org-a');
  assert.equal(deps.getAppointments()[0].status, 'checked_in');
});

await test('appointments:checkin — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Swasthya-Facility': 'fac-a2' });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('appointments:checkin — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('appointments:checkin — a nonexistent appointment is 404', async () => {
  const response = await checkinAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000');
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:checkin — a malformed appointment id is 404 (binding parity)', async () => {
  for (const bad of ['not-a-uuid', '', 'abc']) {
    const response = await checkinAs(SUB_RECEPTIONIST, bad);
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('appointments:checkin — an out-of-scope appointment is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [
      fullAppointment(APPT_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 }),
    ],
  });
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A2_OTHER_FAC, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:checkin — a cross-tenant appointment is 404', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [fullAppointment(APPT_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 })],
  });
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_B1, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:checkin — an already checked-in appointment is 409 (Laravel message)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const first = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', deps);
  const second = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', deps);
  assert.equal(first.status, 200);
  assert.equal(second.status, 409);
  const body = await bodyJson(second);
  assert.equal(body.error.code, 'CONFLICT');
  assert.ok(body.error.message.includes('Only a booked appointment can be checked in'));
  // Exactly one check-in occurred; the token was not re-issued.
  assert.equal(deps.getAppointments().filter((a) => a.status === 'checked_in').length, 1);
  assert.equal(deps.getAppointments()[0].tokenNo, 1);
});

await test('appointments:checkin — cancelled and completed appointments are 409', async () => {
  const cancelled = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', makeDeps({}, {
    seedAppointments: [fullAppointment(APPT_A1, { status: 'cancelled' })],
  }));
  const completed = await checkinAs(SUB_RECEPTIONIST, APPT_A2, {}, '', makeDeps({}, {
    seedAppointments: [fullAppointment(APPT_A2, { status: 'completed' })],
  }));
  for (const response of [cancelled, completed]) {
    assert.equal(response.status, 409);
    const body = await bodyJson(response);
    assert.equal(body.error.code, 'CONFLICT');
    assert.ok(body.error.message.includes('current status'));
  }
});

await test('appointments:checkin — a request body with fields is rejected (422)', async () => {
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, { facilityId: 'fac-a1' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'facilityId');
  assert.equal(body.error.details[0].code, 'NOT_ALLOWED');
});

await test('appointments:checkin — malformed JSON body is 400', async () => {
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '{not json');
  assert.equal(response.status, 400);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_REQUEST');
});

await test('appointments:checkin — an empty object body is accepted (strict contract)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, {}, deps);
  assert.equal(response.status, 200);
  assert.equal((await bodyJson(response)).data.status, 'checked_in');
});

await test('appointments:checkin — transaction failure leaves no partial mutation (500)', async () => {
  const deps = makeDeps(
    { checkInAppointment: () => ({ ok: false, reason: 'ERROR' }) },
    { seedAppointments: [fullAppointment(APPT_A1)] },
  );
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', deps);
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
  assert.equal(deps.getAppointments()[0].status, 'booked');
  assert.equal(deps.getAppointments()[0].tokenNo, null);
});

await test('appointments:checkin — audit is attributed to the actor + authoritative context + token', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const response = await checkinAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Correlation-Id': 'corr-checkin' }, '', deps);
  assert.equal(response.status, 200);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'appointment.checked_in');
  assert.equal(events[0].resourceType, 'appointment');
  assert.equal(events[0].resourceId, APPT_A1);
  assert.equal(events[0].actorId, 'u-receptionist');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-checkin');
  assert.deepEqual(events[0].payload, {
    patientId: PAT_A1, tokenNo: 1, providerStaffId: STAFF_A1,
  });
});

await test('appointments:checkin — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_RECEPTIONIST });
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const ok = await checkinAs(SUB_RECEPTIONIST, APPT_A1, {}, '', deps);
  const err = await handleAppointmentsCheckin(checkinReq(APPT_A1), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
  }
});

await test('appointments:checkin — correlation-id propagation contract', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const ok = await checkinAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Correlation-Id': 'corr-ci-2' }, '', deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-ci-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-ci-2');
  const err = await checkinAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Correlation-Id': 'corr-ci-3' }, '', deps);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-ci-3');
});

/* ------------------------------------------------------------------ */
/* 13. encounters:create (Phase 11 — encounter start / queue handoff)   */
/* ------------------------------------------------------------------ */

function encounterReq(id, headers = {}, body = '') {
  return new Request(`https://example.supabase.co/functions/v1/encounters-create/${id}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === '' ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function encounterAs(sub, id, headers = {}, body = '', deps = makeDeps()) {
  return handleEncountersCreate(
    encounterReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

// The exact EncounterController::present key set.
const ENCOUNTER_KEYS = [
  'id', 'facilityId', 'patientId', 'appointmentId', 'providerStaffId',
  'type', 'status', 'startedAt', 'endedAt', 'signedAt', 'lockVersion',
];

await test('encounters:create — a checked-in appointment starts an open encounter and the appointment moves to in_consultation', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), ENCOUNTER_KEYS.slice().sort());
  assert.equal(body.data.type, 'opd');
  assert.equal(body.data.status, 'open');
  assert.equal(body.data.appointmentId, APPT_A1);
  assert.equal(body.data.patientId, PAT_A1);
  assert.equal(body.data.providerStaffId, STAFF_A1);
  assert.equal(body.data.lockVersion, 0);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // The appointment transitioned in the same transaction.
  const stored = deps.getAppointments()[0];
  assert.equal(stored.status, 'in_consultation');
  assert.equal(stored.lockVersion, 1);
  // The encounter appears in the read path (one encounter per appointment).
  assert.equal(deps.getEncounters().length, 1);
  assert.equal(deps.getEncounters()[0].appointmentId, APPT_A1);
});

await test('encounters:create — a booked (not checked-in) appointment is 409 (Laravel message)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1)] });
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.ok(body.error.message.includes('An encounter can only be started from a checked-in appointment'));
  assert.ok(body.error.message.includes('booked'));
  assert.equal(deps.getEncounters().length, 0);
});

await test('encounters:create — cancelled and completed appointments are 409', async () => {
  for (const [id, status] of [
    [APPT_A1, 'cancelled'],
    [APPT_A2, 'completed'],
    [APPT_A2_OTHER_FAC, 'no_show'],
  ]) {
    const deps = makeDeps({}, { seedAppointments: [fullAppointment(id, { status })] });
    const response = await encounterAs(SUB_FAC_DOCTOR, id, {}, '', deps);
    assert.equal(response.status, 409, `status ${status}`);
    const body = await bodyJson(response);
    assert.equal(body.error.code, 'CONFLICT', `status ${status}`);
    assert.ok(body.error.message.includes('current status'), `status ${status}`);
    assert.equal(deps.getEncounters().length, 0, `status ${status}`);
  }
});

await test('encounters:create rejects unauthenticated requests (401)', async () => {
  const response = await handleEncountersCreate(encounterReq(APPT_A1), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:create rejects a malformed token (401)', async () => {
  const response = await handleEncountersCreate(
    encounterReq(APPT_A1, { Authorization: 'Bearer not-a-jwt' }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:create rejects an expired token (401 TOKEN_EXPIRED)', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_FAC_DOCTOR,
  }, SECRET);
  const response = await handleEncountersCreate(
    encounterReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounters:create rejects an unknown subject (401)', async () => {
  const response = await encounterAs(SUB_UNKNOWN, APPT_A1);
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:create rejects a locked user (403 FORBIDDEN)', async () => {
  const response = await encounterAs(SUB_LOCKED, APPT_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:create rejects a disabled user (403 FORBIDDEN)', async () => {
  const response = await encounterAs(SUB_DISABLED, APPT_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:create rejects a suspended tenant (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await encounterAs(SUB_SUSPENDED, APPT_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounters:create denies an unauthorized capability (403 SCOPE_DENIED)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const response = await encounterAs(SUB_NO_PERM, APPT_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  // No mutation happened.
  assert.equal(deps.getAppointments()[0].status, 'checked_in');
  assert.equal(deps.getEncounters().length, 0);
});

await test('encounters:create fails closed with no context (no assignment)', async () => {
  const response = await encounterAs(SUB_NO_ASSIGNMENT, APPT_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:create — forged app_* claims are inert', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const token = await gotrueToken({
    sub: SUB_FAC_DOCTOR,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncountersCreate(
    encounterReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.data.facilityId, 'fac-a1');
  assert.equal(deps.getAppointments()[0].status, 'in_consultation');
});

await test('encounters:create — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, { 'X-Swasthya-Facility': 'fac-a2' });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounters:create — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounters:create — a nonexistent appointment is 404', async () => {
  const response = await encounterAs(SUB_FAC_DOCTOR, 'ffffffff-0000-4000-8000-000000000000');
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:create — a malformed appointment id is 404 (binding parity)', async () => {
  for (const bad of ['not-a-uuid', '', 'abc']) {
    const response = await encounterAs(SUB_FAC_DOCTOR, bad);
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('encounters:create — an out-of-scope appointment is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [fullAppointment(APPT_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2, status: 'checked_in' })],
  });
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A2_OTHER_FAC, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:create — a cross-tenant appointment is 404', async () => {
  const deps = makeDeps({}, {
    seedAppointments: [fullAppointment(APPT_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1, status: 'checked_in' })],
  });
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_B1, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:create — a request body with fields is rejected (422)', async () => {
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, { facilityId: 'fac-a1' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'facilityId');
  assert.equal(body.error.details[0].code, 'NOT_ALLOWED');
});

await test('encounters:create — malformed JSON body is 400', async () => {
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '{not json');
  assert.equal(response.status, 400);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_REQUEST');
});

await test('encounters:create — an empty object body is accepted (strict contract)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, {}, deps);
  assert.equal(response.status, 201);
  assert.equal((await bodyJson(response)).data.status, 'open');
});

await test('encounters:create — a duplicate start is 409 and never creates a second encounter', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const first = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  const second = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  assert.equal(first.status, 201);
  assert.equal(second.status, 409);
  const body = await bodyJson(second);
  assert.equal(body.error.code, 'CONFLICT');
  assert.ok(body.error.message.includes('current status: in_consultation'));
  // Exactly one encounter exists for the appointment.
  assert.equal(deps.getEncounters().length, 1);
  assert.equal(deps.getAppointments()[0].status, 'in_consultation');
});

await test('encounters:create — transition failure leaves no partial encounter (rollback)', async () => {
  const deps = makeDeps(
    { startEncounter: () => ({ ok: false, reason: 'NOT_CHECKED_IN' }) },
    { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] },
  );
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  assert.equal(response.status, 409);
  assert.equal(deps.getAppointments()[0].status, 'checked_in');
  assert.equal(deps.getAppointments()[0].lockVersion, 0);
  assert.equal(deps.getEncounters().length, 0);
});

await test('encounters:create — transaction failure returns 500 with no partial mutation', async () => {
  const deps = makeDeps(
    { startEncounter: () => ({ ok: false, reason: 'ERROR' }) },
    { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] },
  );
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
  assert.equal(deps.getAppointments()[0].status, 'checked_in');
  assert.equal(deps.getEncounters().length, 0);
});

await test('encounters:create — audit is attributed to the actor + authoritative context + appointment', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const response = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, { 'X-Correlation-Id': 'corr-enc' }, '', deps);
  assert.equal(response.status, 201);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'encounter.started');
  assert.equal(events[0].resourceType, 'encounter');
  assert.equal(events[0].resourceId, deps.getEncounters()[0].id);
  assert.equal(events[0].actorId, 'u-fac-doctor');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-enc');
  assert.deepEqual(events[0].payload, {
    patientId: PAT_A1, appointmentId: APPT_A1, providerStaffId: STAFF_A1,
  });
});

await test('encounters:create — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const ok = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, {}, '', deps);
  const err = await handleEncountersCreate(encounterReq(APPT_A1), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
  }
});

await test('encounters:create — correlation-id propagation contract', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { status: 'checked_in' })] });
  const ok = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, { 'X-Correlation-Id': 'corr-enc-2' }, '', deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-enc-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-enc-2');
  const err = await encounterAs(SUB_FAC_DOCTOR, APPT_A1, { 'X-Correlation-Id': 'corr-enc-3' }, {}, deps);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-enc-3');
});

/* ------------------------------------------------------------------ */
/* 14. encounter-notes:draft (Phase 12 — clinical documentation)         */
/* ------------------------------------------------------------------ */

const ENC_A1 = 'aaaaaaaa-0000-4000-8000-000000000d01';
const ENC_A1_SIGNED = 'aaaaaaaa-0000-4000-8000-000000000d02';
const ENC_A2_OTHER_FAC = 'aaaaaaaa-0000-4000-8000-000000000d03';
const ENC_B1 = 'aaaaaaaa-0000-4000-8000-000000000d04';

/** A full EncounterRow-shaped seed (the shape the notes handler reads). */
function fullEncounter(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
    appointmentId: APPT_A1, providerStaffId: STAFF_A1, type: 'opd', status: 'open',
    startedAt: '2026-03-02T09:05:01Z', endedAt: null, signedAt: null, lockVersion: 0,
    ...overrides,
  };
}

function noteReq(id, headers = {}, body = undefined) {
  return new Request(`https://example.supabase.co/functions/v1/encounters-notes-draft/${id}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === undefined ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function noteAs(sub, id, headers = {}, body = undefined, deps = makeDeps()) {
  return handleEncounterNotesDraft(
    noteReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

// The exact EncounterController::storeNote response key set.
const NOTE_KEYS = ['id', 'noteType', 'author', 'content', 'status'];

// A valid structured content object (StoreClinicalNoteRequest shape).
const CONTENT = { complaint: 'Fever since yesterday', plan: 'Paracetamol, review in 3 days.' };

await test('encounter-notes:draft — the encounter provider drafts a note (201, author derived server-side)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), NOTE_KEYS.slice().sort());
  assert.equal(body.data.noteType, 'consultation');
  assert.deepEqual(body.data.author, { id: STAFF_A1, fullName: 'Dr. Kiran Adhikari' });
  assert.deepEqual(body.data.content, CONTENT);
  assert.equal(body.data.status, 'draft');
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // The author_staff_id in the stored note is the provider's staff row.
  assert.equal(deps.getNotes()[0].authorStaffId, STAFF_A1);
  assert.equal(deps.getNotes()[0].encounterId, ENC_A1);
});

await test('encounter-notes:draft — a custom noteType is honored (enum parity)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { noteType: 'nursing', content: { vitals: 'BP 120/80' } }, deps);
  assert.equal(response.status, 201);
  assert.equal((await bodyJson(response)).data.noteType, 'nursing');
});

await test('encounter-notes:draft — multiple drafts per encounter are permitted (Laravel parity)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const first = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: { complaint: 'a' } }, deps);
  const second = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: { complaint: 'b' } }, deps);
  assert.equal(first.status, 201);
  assert.equal(second.status, 201);
  assert.equal(deps.getNotes().length, 2);
});

await test('encounter-notes:draft — a non-provider clinician is denied 403 (clinical-safety author rule)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1, { providerStaffId: STAFF_A1 })] });
  // SUB_DOCTOR_B has encounter:document but is STAFF_A1_EXCEPTION — NOT the
  // encounter provider.
  const response = await noteAs(SUB_DOCTOR_B, ENC_A1, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'Only the encounter provider can document this visit.');
  assert.equal(deps.getNotes().length, 0);
});

await test('encounter-notes:draft — a signed (non-open) encounter rejects clinical content (409, Laravel message)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1_SIGNED, { status: 'signed', signedAt: '2026-03-02T11:00:00Z' })] });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1_SIGNED, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(
    body.error.message,
    'Clinical content cannot be added to a signed encounter — amendment is the only path (later phase).',
  );
  assert.equal(deps.getNotes().length, 0);
});

await test('encounter-notes:draft — any non-open encounter status is 409', async () => {
  for (const status of ['in_progress', 'amended', 'closed']) {
    const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1, { status })] });
    const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: CONTENT }, deps);
    assert.equal(response.status, 409, `status ${status}`);
    assert.equal((await bodyJson(response)).error.code, 'CONFLICT', `status ${status}`);
    assert.equal(deps.getNotes().length, 0, `status ${status}`);
  }
});

await test('encounter-notes:draft rejects unauthenticated requests (401)', async () => {
  const response = await handleEncounterNotesDraft(noteReq(ENC_A1), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounter-notes:draft rejects a malformed token (401)', async () => {
  const response = await handleEncounterNotesDraft(
    noteReq(ENC_A1, { Authorization: 'Bearer not-a-jwt' }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounter-notes:draft rejects an expired token (401 TOKEN_EXPIRED)', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_FAC_DOCTOR,
  }, SECRET);
  const response = await handleEncounterNotesDraft(
    noteReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounter-notes:draft rejects an unknown subject (401)', async () => {
  const response = await noteAs(SUB_UNKNOWN, ENC_A1, {}, { content: CONTENT });
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounter-notes:draft rejects a locked user (403 FORBIDDEN)', async () => {
  const response = await noteAs(SUB_LOCKED, ENC_A1, {}, { content: CONTENT });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounter-notes:draft rejects a disabled user (403 FORBIDDEN)', async () => {
  const response = await noteAs(SUB_DISABLED, ENC_A1, {}, { content: CONTENT });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounter-notes:draft rejects a suspended tenant (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await noteAs(SUB_SUSPENDED, ENC_A1, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounter-notes:draft denies a missing capability (403 SCOPE_DENIED)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const response = await noteAs(SUB_NO_PERM, ENC_A1, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  assert.equal(deps.getNotes().length, 0);
});

await test('encounter-notes:draft fails closed with no context (no assignment)', async () => {
  const response = await noteAs(SUB_NO_ASSIGNMENT, ENC_A1, {}, { content: CONTENT });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounter-notes:draft — forged app_* claims are inert', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const token = await gotrueToken({
    sub: SUB_FAC_DOCTOR,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncounterNotesDraft(
    noteReq(ENC_A1, { Authorization: `Bearer ${token}` }, { content: CONTENT }),
    deps,
  );
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.data.author.id, STAFF_A1);
});

await test('encounter-notes:draft — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Swasthya-Facility': 'fac-a2' }, { content: CONTENT });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounter-notes:draft — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  }, { content: CONTENT });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounter-notes:draft — client-supplied author_staff_id is rejected (422 NOT_ALLOWED)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, {
    content: CONTENT, author_staff_id: STAFF_A1,
  });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'author_staff_id');
  assert.equal(body.error.details[0].code, 'NOT_ALLOWED');
  assert.equal(body.error.details[0].message, 'Field "author_staff_id" is not allowed.');
});

await test('encounter-notes:draft — client-supplied tenant/facility are rejected (422 NOT_ALLOWED)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, {
    content: CONTENT, tenant_id: 'org-b', facility_id: 'fac-b',
  });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.details.length, 2);
  for (const d of body.error.details) assert.equal(d.code, 'NOT_ALLOWED');
});

await test('encounter-notes:draft — a nonexistent encounter is 404', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, 'ffffffff-0000-4000-8000-000000000000', {}, { content: CONTENT });
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounter-notes:draft — a malformed encounter id is 404 (binding parity)', async () => {
  for (const bad of ['not-a-uuid', '', 'abc']) {
    const response = await noteAs(SUB_FAC_DOCTOR, bad, {}, { content: CONTENT });
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('encounter-notes:draft — an out-of-scope encounter is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 })],
  });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A2_OTHER_FAC, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounter-notes:draft — a cross-tenant encounter is 404', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 })],
  });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_B1, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounter-notes:draft — empty body is 422 (content required)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1);
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'content');
  assert.equal(body.error.details[0].code, 'REQUIRED');
});

await test('encounter-notes:draft — malformed JSON body is 400', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, '{not json');
  assert.equal(response.status, 400);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_REQUEST');
});

await test('encounter-notes:draft — content must be a non-empty object (422)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  for (const badContent of [[], {}, ['section'], 'text', 42, null, { plan: 7 }]) {
    const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: badContent }, deps);
    assert.equal(response.status, 422, `content ${JSON.stringify(badContent)}`);
    const body = await bodyJson(response);
    assert.equal(body.error.code, 'VALIDATION_ERROR', `content ${JSON.stringify(badContent)}`);
    assert.equal(body.error.details[0].field, 'content', `content ${JSON.stringify(badContent)}`);
  }
  assert.equal(deps.getNotes().length, 0);
});

await test('encounter-notes:draft — a content section longer than 10000 chars is rejected (422)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: { plan: 'x'.repeat(10001) } });
  assert.equal(response.status, 422);
  assert.equal((await bodyJson(response)).error.code, 'VALIDATION_ERROR');
});

await test('encounter-notes:draft — an invalid noteType is rejected (422)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { noteType: 'emergency', content: CONTENT });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'noteType');
});

await test('encounter-notes:draft — unknown top-level fields are rejected (422)', async () => {
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: CONTENT, madeUp: true });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'madeUp');
  assert.equal(body.error.details[0].code, 'NOT_ALLOWED');
});

await test('encounter-notes:draft — a null content section is tolerated (validator parity)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: { complaint: null } }, deps);
  assert.equal(response.status, 201);
  assert.deepEqual((await bodyJson(response)).data.content, { complaint: null });
});

await test('encounter-notes:draft — transaction failure returns 500 with no partial note (rollback)', async () => {
  const deps = makeDeps(
    { createDraftNote: () => ({ ok: false, reason: 'ERROR' }) },
    { seedEncounters: [fullEncounter(ENC_A1)] },
  );
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: CONTENT }, deps);
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
  assert.equal(deps.getNotes().length, 0);
});

await test('encounter-notes:draft — audit is attributed to the actor + authoritative context + encounter', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const response = await noteAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Correlation-Id': 'corr-note' }, { content: CONTENT }, deps);
  assert.equal(response.status, 201);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'note.drafted');
  assert.equal(events[0].resourceType, 'clinical_note');
  assert.equal(events[0].resourceId, deps.getNotes()[0].id);
  assert.equal(events[0].actorId, 'u-fac-doctor');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-note');
  assert.deepEqual(events[0].payload, {
    encounterId: ENC_A1, noteType: 'consultation', authorStaffId: STAFF_A1,
  });
});

await test('encounter-notes:draft — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const ok = await noteAs(SUB_FAC_DOCTOR, ENC_A1, {}, { content: CONTENT }, deps);
  const err = await handleEncounterNotesDraft(noteReq(ENC_A1), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
  }
});

await test('encounter-notes:draft — correlation-id propagation contract', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const ok = await noteAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Correlation-Id': 'corr-note-2' }, { content: CONTENT }, deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-note-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-note-2');
  const err = await noteAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Correlation-Id': 'corr-note-3' }, { madeUp: 1 }, deps);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-note-3');
});

/* ------------------------------------------------------------------ */
/* 15. encounter-notes:sign (Phase 13 — note signing / immutability)     */
/* ------------------------------------------------------------------ */

const NOTE_A1 = 'aaaaaaaa-0000-4000-8000-000000000e01';
const NOTE_A1_OTHER_AUTHOR = 'aaaaaaaa-0000-4000-8000-000000000e02';
const NOTE_OTHER_ENC = 'aaaaaaaa-0000-4000-8000-000000000e03';
const NOTE_SIGNED = 'aaaaaaaa-0000-4000-8000-000000000e04';

/** A full NoteSignRow-shaped seed (draft by the encounter provider). */
function fullNote(id, encounterId = ENC_A1, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', encounterId,
    noteType: 'consultation', authorStaffId: STAFF_A1,
    content: { complaint: 'Fever' }, status: 'draft', signedAt: null, lockVersion: 0,
    ...overrides,
  };
}

function signReq(encounterId, noteId, headers = {}, body = '') {
  return new Request(`https://example.supabase.co/functions/v1/encounters-notes-sign/${encounterId}/${noteId}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === '' ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function signAs(sub, encounterId, noteId, headers = {}, body = '', deps = makeDeps()) {
  return handleEncounterNotesSign(
    signReq(encounterId, noteId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

// The exact EncounterController::signNote response key set.
const SIGN_KEYS = ['id', 'status', 'signedAt'];

await test('encounter-notes:sign — the provider signs their own draft note (200, signed_at server-side)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), SIGN_KEYS.slice().sort());
  assert.equal(body.data.id, NOTE_A1);
  assert.equal(body.data.status, 'signed');
  assert.equal(body.data.signedAt, '2026-03-02T10:00:00Z');
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // The stored note transitioned exactly once, lock_version incremented.
  const stored = deps.getNotes()[0];
  assert.equal(stored.status, 'signed');
  assert.equal(stored.signedAt, '2026-03-02T10:00:00Z');
  assert.equal(stored.lockVersion, 1);
});

await test('encounter-notes:sign — a signed note cannot be signed again (immutability, 409)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const first = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  const second = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(first.status, 200);
  assert.equal(second.status, 409);
  const body = await bodyJson(second);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'Only a draft note can be signed.');
  // Exactly one signed transition occurred.
  assert.equal(deps.getNotes()[0].status, 'signed');
  assert.equal(deps.getNotes()[0].lockVersion, 1);
});

await test('encounter-notes:sign — a non-provider clinician is denied (403, encounter-provider rule)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const response = await signAs(SUB_DOCTOR_B, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'Only the encounter provider can document this visit.');
  assert.equal(deps.getNotes()[0].status, 'draft');
});

await test('encounter-notes:sign — the provider cannot sign a note they did not author (403, note-author rule)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1)],
    seedNotes: [fullNote(NOTE_A1_OTHER_AUTHOR, ENC_A1, { authorStaffId: STAFF_A1_EXCEPTION })],
  });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1_OTHER_AUTHOR, {}, '', deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'Only the note author can sign it.');
  assert.equal(deps.getNotes()[0].status, 'draft');
});

await test('encounter-notes:sign rejects unauthenticated requests (401)', async () => {
  const response = await handleEncounterNotesSign(signReq(ENC_A1, NOTE_A1), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounter-notes:sign rejects a malformed token (401)', async () => {
  const response = await handleEncounterNotesSign(
    signReq(ENC_A1, NOTE_A1, { Authorization: 'Bearer not-a-jwt' }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounter-notes:sign rejects an expired token (401 TOKEN_EXPIRED)', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_FAC_DOCTOR,
  }, SECRET);
  const response = await handleEncounterNotesSign(
    signReq(ENC_A1, NOTE_A1, { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounter-notes:sign rejects an unknown subject (401)', async () => {
  const response = await signAs(SUB_UNKNOWN, ENC_A1, NOTE_A1);
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounter-notes:sign rejects a locked user (403 FORBIDDEN)', async () => {
  const response = await signAs(SUB_LOCKED, ENC_A1, NOTE_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounter-notes:sign rejects a disabled user (403 FORBIDDEN)', async () => {
  const response = await signAs(SUB_DISABLED, ENC_A1, NOTE_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounter-notes:sign rejects a suspended tenant (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await signAs(SUB_SUSPENDED, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounter-notes:sign denies a missing capability (403 SCOPE_DENIED)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const response = await signAs(SUB_NO_PERM, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  assert.equal(deps.getNotes()[0].status, 'draft');
});

await test('encounter-notes:sign fails closed with no context (no assignment)', async () => {
  const response = await signAs(SUB_NO_ASSIGNMENT, ENC_A1, NOTE_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounter-notes:sign — forged app_* claims are inert', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const token = await gotrueToken({
    sub: SUB_FAC_DOCTOR,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncounterNotesSign(
    signReq(ENC_A1, NOTE_A1, { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  assert.equal((await bodyJson(response)).meta.context.tenantId, 'org-a');
  assert.equal(deps.getNotes()[0].status, 'signed');
});

await test('encounter-notes:sign — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, { 'X-Swasthya-Facility': 'fac-a2' });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounter-notes:sign — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounter-notes:sign — client-forged signed_at/status/lock_version are rejected (422 NOT_ALLOWED)', async () => {
  for (const forged of [
    { signed_at: '2020-01-01T00:00:00Z' },
    { status: 'signed' },
    { lock_version: 99 },
  ]) {
    const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, forged);
    assert.equal(response.status, 422, JSON.stringify(forged));
    const body = await bodyJson(response);
    assert.equal(body.error.code, 'VALIDATION_ERROR', JSON.stringify(forged));
    assert.equal(body.error.details[0].code, 'NOT_ALLOWED', JSON.stringify(forged));
  }
});

await test('encounter-notes:sign — unknown body fields are rejected (422)', async () => {
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, { madeUp: true });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'madeUp');
});

await test('encounter-notes:sign — a nonexistent encounter is 404', async () => {
  const response = await signAs(SUB_FAC_DOCTOR, 'ffffffff-0000-4000-8000-000000000000', NOTE_A1);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounter-notes:sign — malformed ids are 404 (binding parity)', async () => {
  for (const [enc, note] of [['not-a-uuid', NOTE_A1], [ENC_A1, 'not-a-uuid'], ['', ''], ['abc', 'def']]) {
    const response = await signAs(SUB_FAC_DOCTOR, enc, note);
    assert.equal(response.status, 404, `${enc}/${note}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `${enc}/${note}`);
  }
});

await test('encounter-notes:sign — an out-of-scope encounter is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 })],
    seedNotes: [fullNote(NOTE_A1, ENC_A2_OTHER_FAC)],
  });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A2_OTHER_FAC, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounter-notes:sign — a cross-tenant encounter is 404', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 })],
    seedNotes: [fullNote(NOTE_A1, ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b' })],
  });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_B1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounter-notes:sign — a note of a different encounter is 404 (note-encounter binding)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1)],
    seedNotes: [fullNote(NOTE_OTHER_ENC, 'aaaaaaaa-0000-4000-8000-000000000dd1')],
  });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_OTHER_ENC, {}, '', deps);
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Note not found on this encounter.');
});

await test('encounter-notes:sign — a nonexistent note is 404 (Laravel message)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)] });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, 'ffffffff-0000-4000-8000-0000000000ff', {}, '', deps);
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Note not found on this encounter.');
});

await test('encounter-notes:sign — an amended note cannot be signed (409)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1)],
    seedNotes: [fullNote(NOTE_A1, ENC_A1, { status: 'amended', signedAt: '2026-03-02T09:00:00Z' })],
  });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 409);
  assert.equal((await bodyJson(response)).error.code, 'CONFLICT');
});

await test('encounter-notes:sign — concurrent signing yields exactly one success (guarded transition)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const first = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  const second = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(first.status, 200);
  assert.equal(second.status, 409);
  assert.equal((await bodyJson(second)).error.message, 'Only a draft note can be signed.');
  assert.equal(deps.getNotes()[0].status, 'signed');
  assert.equal(deps.getNotes()[0].lockVersion, 1);
  // Exactly one successful sign → exactly one note.signed audit event.
  assert.equal(deps.getAuditEvents().filter((e) => e.action === 'note.signed').length, 1);
});

await test('encounter-notes:sign — transition failure returns 500 with no partial mutation', async () => {
  const deps = makeDeps(
    { signNote: () => ({ ok: false, reason: 'ERROR' }) },
    { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] },
  );
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
  assert.equal(deps.getNotes()[0].status, 'draft');
  assert.equal(deps.getNotes()[0].signedAt, null);
  assert.equal(deps.getNotes()[0].lockVersion, 0);
});

await test('encounter-notes:sign — audit is attributed to the actor + authoritative context + note', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const response = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, { 'X-Correlation-Id': 'corr-sign' }, '', deps);
  assert.equal(response.status, 200);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'note.signed');
  assert.equal(events[0].resourceType, 'clinical_note');
  assert.equal(events[0].resourceId, NOTE_A1);
  assert.equal(events[0].actorId, 'u-fac-doctor');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-sign');
  assert.deepEqual(events[0].payload, { encounterId: ENC_A1, authorStaffId: STAFF_A1 });
});

await test('encounter-notes:sign — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const ok = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, {}, '', deps);
  const err = await handleEncounterNotesSign(signReq(ENC_A1, NOTE_A1), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
  }
});

await test('encounter-notes:sign — correlation-id propagation contract', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1)], seedNotes: [fullNote(NOTE_A1)] });
  const ok = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, { 'X-Correlation-Id': 'corr-sign-2' }, '', deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-sign-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-sign-2');
  const err = await signAs(SUB_FAC_DOCTOR, ENC_A1, NOTE_A1, { 'X-Correlation-Id': 'corr-sign-3' }, { x: 1 }, deps);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-sign-3');
});

/* ------------------------------------------------------------------ */
/* 16. encounters:sign (Phase 14 — encounter signing / immutability)     */
/* ------------------------------------------------------------------ */

function encSignReq(id, headers = {}, body = '') {
  return new Request(`https://example.supabase.co/functions/v1/encounters-sign/${id}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === '' ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function encSignAs(sub, id, headers = {}, body = '', deps = makeDeps()) {
  return handleEncountersSign(
    encSignReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

// A fully eligible fixture set: open encounter (provider STAFF_A1) linked to
// an in_consultation appointment + one signed note on the encounter.
function signFixtureDeps(encounterOverrides = {}, appointmentOverrides = {}) {
  return makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1, { appointmentId: APPT_A1, ...encounterOverrides })],
    seedAppointments: [fullAppointment(APPT_A1, { status: 'in_consultation', ...appointmentOverrides })],
    seedNotes: [fullNote(NOTE_A1, ENC_A1, { status: 'signed', signedAt: '2026-03-02T10:00:00Z' })],
  });
}

await test('encounters:sign — the provider signs the open encounter and the in_consultation appointment completes (200)', async () => {
  const deps = signFixtureDeps();
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), ENCOUNTER_KEYS.slice().sort());
  assert.equal(body.data.id, ENC_A1);
  assert.equal(body.data.status, 'signed');
  assert.equal(body.data.endedAt, '2026-03-02T11:00:00Z');
  assert.equal(body.data.signedAt, '2026-03-02T11:00:00Z');
  assert.equal(body.data.lockVersion, 1);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // The encounter transitioned exactly once; server-derived fields stored.
  const stored = deps.getEncounters()[0];
  assert.equal(stored.status, 'signed');
  assert.equal(stored.signedBy, 'u-fac-doctor');
  assert.equal(stored.endedAt, '2026-03-02T11:00:00Z');
  assert.equal(stored.lockVersion, 1);
  // The appointment handoff happened in the same transaction.
  assert.equal(deps.getAppointments()[0].status, 'completed');
  assert.equal(deps.getAppointments()[0].lockVersion, 1);
});

await test('encounters:sign — the appointment handoff silently skips when the appointment is already completed (Laravel parity)', async () => {
  const deps = signFixtureDeps({}, { status: 'completed', lockVersion: 1 });
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 200);
  assert.equal((await bodyJson(response)).data.status, 'signed');
  // Encounter signed; appointment untouched (still completed, same version).
  assert.equal(deps.getEncounters()[0].status, 'signed');
  assert.equal(deps.getAppointments()[0].status, 'completed');
  assert.equal(deps.getAppointments()[0].lockVersion, 1);
});

await test('encounters:sign — the appointment handoff silently skips when the encounter has no appointment', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1, { appointmentId: null })],
    seedNotes: [fullNote(NOTE_A1, ENC_A1, { status: 'signed', signedAt: '2026-03-02T10:00:00Z' })],
  });
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 200);
  assert.equal((await bodyJson(response)).data.status, 'signed');
  assert.equal(deps.getEncounters()[0].status, 'signed');
});

await test('encounters:sign — an encounter without a signed note is 409 (Laravel message)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1, { appointmentId: APPT_A1 })],
    seedAppointments: [fullAppointment(APPT_A1, { status: 'in_consultation' })],
    seedNotes: [fullNote(NOTE_A1, ENC_A1, { status: 'draft' })],
  });
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'An encounter must contain at least one signed note before signing.');
  assert.equal(deps.getEncounters()[0].status, 'open');
  assert.equal(deps.getAppointments()[0].status, 'in_consultation');
});

await test('encounters:sign — a signed encounter cannot be signed again (immutability, 409)', async () => {
  const deps = signFixtureDeps({ status: 'signed', signedAt: '2026-03-02T10:00:00Z', lockVersion: 1 });
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.ok(body.error.message.includes('Only an open encounter can be signed'));
  assert.ok(body.error.message.includes('signed'));
  assert.equal(deps.getEncounters()[0].lockVersion, 1);
});

await test('encounters:sign — any non-open encounter status is 409', async () => {
  for (const status of ['in_progress', 'amended', 'closed']) {
    const deps = makeDeps({}, {
      seedEncounters: [fullEncounter(ENC_A1, { status })],
      seedNotes: [fullNote(NOTE_A1, ENC_A1, { status: 'signed', signedAt: '2026-03-02T10:00:00Z' })],
    });
    const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
    assert.equal(response.status, 409, `status ${status}`);
    assert.equal((await bodyJson(response)).error.code, 'CONFLICT', `status ${status}`);
  }
});

await test('encounters:sign — a non-provider clinician is denied (403, encounter-provider rule)', async () => {
  const deps = signFixtureDeps();
  const response = await encSignAs(SUB_DOCTOR_B, ENC_A1, {}, '', deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'Only the encounter provider can document this visit.');
  assert.equal(deps.getEncounters()[0].status, 'open');
});

await test('encounters:sign rejects unauthenticated requests (401)', async () => {
  const response = await handleEncountersSign(encSignReq(ENC_A1), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:sign rejects a malformed token (401)', async () => {
  const response = await handleEncountersSign(
    encSignReq(ENC_A1, { Authorization: 'Bearer not-a-jwt' }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:sign rejects an expired token (401 TOKEN_EXPIRED)', async () => {
  const token = await signJwt({
    iss: ISSUER, aud: AUDIENCE, iat: NOW - 7200, exp: NOW - 3600, sub: SUB_FAC_DOCTOR,
  }, SECRET);
  const response = await handleEncountersSign(
    encSignReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounters:sign rejects an unknown subject (401)', async () => {
  const response = await encSignAs(SUB_UNKNOWN, ENC_A1);
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:sign rejects a locked user (403 FORBIDDEN)', async () => {
  const response = await encSignAs(SUB_LOCKED, ENC_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:sign rejects a disabled user (403 FORBIDDEN)', async () => {
  const response = await encSignAs(SUB_DISABLED, ENC_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:sign rejects a suspended tenant (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'organization',
      role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await encSignAs(SUB_SUSPENDED, ENC_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounters:sign denies a missing capability (403 SCOPE_DENIED)', async () => {
  const deps = signFixtureDeps();
  const response = await encSignAs(SUB_NO_PERM, ENC_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  assert.equal(deps.getEncounters()[0].status, 'open');
});

await test('encounters:sign fails closed with no context (no assignment)', async () => {
  const response = await encSignAs(SUB_NO_ASSIGNMENT, ENC_A1);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:sign — forged app_* claims are inert', async () => {
  const deps = signFixtureDeps();
  const token = await gotrueToken({
    sub: SUB_FAC_DOCTOR,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncountersSign(
    encSignReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.data.status, 'signed');
});

await test('encounters:sign — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Swasthya-Facility': 'fac-a2' });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounters:sign — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {
    'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b',
  });
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounters:sign — client-forged ended_at/signed_at/signed_by/status/lock_version are rejected (422 NOT_ALLOWED)', async () => {
  for (const forged of [
    { ended_at: '2020-01-01T00:00:00Z' },
    { signed_at: '2020-01-01T00:00:00Z' },
    { signed_by: 'u-attacker' },
    { status: 'signed' },
    { lock_version: 99 },
  ]) {
    const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, forged);
    assert.equal(response.status, 422, JSON.stringify(forged));
    const body = await bodyJson(response);
    assert.equal(body.error.code, 'VALIDATION_ERROR', JSON.stringify(forged));
    assert.equal(body.error.details[0].code, 'NOT_ALLOWED', JSON.stringify(forged));
  }
});

await test('encounters:sign — a nonexistent encounter is 404', async () => {
  const response = await encSignAs(SUB_FAC_DOCTOR, 'ffffffff-0000-4000-8000-000000000000');
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:sign — a malformed encounter id is 404 (binding parity)', async () => {
  for (const bad of ['not-a-uuid', '', 'abc']) {
    const response = await encSignAs(SUB_FAC_DOCTOR, bad);
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('encounters:sign — an out-of-scope encounter is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 })],
    seedNotes: [fullNote(NOTE_A1, ENC_A2_OTHER_FAC, { status: 'signed', signedAt: '2026-03-02T10:00:00Z' })],
  });
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A2_OTHER_FAC, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:sign — a cross-tenant encounter is 404', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 })],
    seedNotes: [fullNote(NOTE_A1, ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b', status: 'signed' })],
  });
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_B1, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:sign — concurrent signing yields exactly one success (guarded transition)', async () => {
  const deps = signFixtureDeps();
  const first = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  const second = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(first.status, 200);
  assert.equal(second.status, 409);
  assert.ok((await bodyJson(second)).error.message.includes('Only an open encounter can be signed'));
  assert.equal(deps.getEncounters()[0].status, 'signed');
  assert.equal(deps.getEncounters()[0].lockVersion, 1);
  assert.equal(deps.getAppointments()[0].status, 'completed');
  // Exactly one successful sign → exactly one encounter.signed audit event.
  assert.equal(deps.getAuditEvents().filter((e) => e.action === 'encounter.signed').length, 1);
});

await test('encounters:sign — transition failure returns 500 with no partial mutation (rollback)', async () => {
  const deps = makeDeps(
    { signEncounter: () => ({ ok: false, reason: 'ERROR' }) },
    {
      seedEncounters: [fullEncounter(ENC_A1, { appointmentId: APPT_A1 })],
      seedAppointments: [fullAppointment(APPT_A1, { status: 'in_consultation' })],
      seedNotes: [fullNote(NOTE_A1, ENC_A1, { status: 'signed', signedAt: '2026-03-02T10:00:00Z' })],
    },
  );
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
  // No partial mutation: encounter still open, appointment still in_consultation.
  assert.equal(deps.getEncounters()[0].status, 'open');
  assert.equal(deps.getEncounters()[0].lockVersion, 0);
  assert.equal(deps.getAppointments()[0].status, 'in_consultation');
});

await test('encounters:sign — audit is attributed to the actor + authoritative context + encounter', async () => {
  const deps = signFixtureDeps();
  const response = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Correlation-Id': 'corr-enc-sign' }, '', deps);
  assert.equal(response.status, 200);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'encounter.signed');
  assert.equal(events[0].resourceType, 'encounter');
  assert.equal(events[0].resourceId, ENC_A1);
  assert.equal(events[0].actorId, 'u-fac-doctor');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-enc-sign');
  assert.deepEqual(events[0].payload, {
    patientId: PAT_A1, providerStaffId: STAFF_A1, appointmentId: APPT_A1,
  });
});

await test('encounters:sign — no audit event is recorded on a failed sign', async () => {
  const deps = signFixtureDeps();
  const first = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  const second = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(first.status, 200);
  assert.equal(second.status, 409);
  assert.equal(deps.getAuditEvents().filter((e) => e.action === 'encounter.signed').length, 1);
});

await test('encounters:sign — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_FAC_DOCTOR });
  const deps = signFixtureDeps();
  const ok = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  const err = await handleEncountersSign(encSignReq(ENC_A1), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
  }
});

await test('encounters:sign — correlation-id propagation contract', async () => {
  const deps = signFixtureDeps();
  const ok = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Correlation-Id': 'corr-es-2' }, '', deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-es-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-es-2');
  const err = await encSignAs(SUB_FAC_DOCTOR, ENC_A1, { 'X-Correlation-Id': 'corr-es-3' }, { x: 1 }, deps);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-es-3');
});

/* ------------------------------------------------------------------ */
/* 17. encounters:invoice (Phase 15 — invoice issue / billing)          */
/* ------------------------------------------------------------------ */

const SVC_A1 = 'aaaaaaaa-0000-4000-8000-000000000f01';
const SVC_A1_NULL_CHARGE = 'aaaaaaaa-0000-4000-8000-000000000f02';
const MED_A1 = 'aaaaaaaa-0000-4000-8000-000000000f03';
const MED_A2 = 'aaaaaaaa-0000-4000-8000-000000000f04';
const RX_A1 = 'aaaaaaaa-0000-4000-8000-000000000f05';
const CHARGE_MANUAL = 'aaaaaaaa-0000-4000-8000-000000000f06';

// Server-side billing catalogs (mirror of the services/medications rows).
// Money is integer minor units end to end (DATABASE.md §0.4).
const services = [
  { id: SVC_A1, tenantId: 'org-a', facilityId: 'fac-a1', name: 'General OPD', defaultChargeMinor: 50000, currency: 'NPR' },
  { id: SVC_A1_NULL_CHARGE, tenantId: 'org-a', facilityId: 'fac-a1', name: 'Follow-up', defaultChargeMinor: null, currency: 'NPR' },
];

const medications = [
  { id: MED_A1, tenantId: 'org-a', genericName: 'Paracetamol', strength: '500mg', priceMinor: 250, currency: 'NPR' },
  { id: MED_A2, tenantId: 'org-a', genericName: 'Amoxicillin', strength: '250mg', priceMinor: 1200, currency: 'NPR' },
];

// The encounter's prescription: two ordered lines (500 + 3600), a cancelled
// line (skipped), and an ordered line with a NULL quantity (→ max(1, 1) = 1
// → 250). Prescription total 4350; consultation 50000; invoice total 54350.
const prescriptions = [
  { id: RX_A1, tenantId: 'org-a', encounterId: ENC_A1, lines: [
    { status: 'ordered', quantityMinor: 2, medicationId: MED_A1 },
    { status: 'ordered', quantityMinor: 3, medicationId: MED_A2 },
    { status: 'cancelled', quantityMinor: 4, medicationId: MED_A1 },
    { status: 'ordered', quantityMinor: null, medicationId: MED_A1 },
  ] },
];

function invoiceReq(id, headers = {}, body = '') {
  return new Request(`https://example.supabase.co/functions/v1/encounters-invoice/${id}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === '' ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function invoiceAs(sub, id, headers = {}, body = '', deps = makeDeps()) {
  return handleEncountersInvoice(
    invoiceReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

// The exact EncounterController::invoice response key sets.
const INVOICE_KEYS = ['id', 'invoiceNumber', 'status', 'totalMinor', 'totalTaxMinor', 'paidMinor', 'lines'];
const INVOICE_LINE_KEYS = ['description', 'amountMinor', 'taxMinor'];

// A fully eligible fixture set: a SIGNED encounter (provider STAFF_A1) on an
// appointment with a service rate + a prescription with ordered lines.
function invoiceFixtureDeps({
  encounterOverrides = {}, appointmentOverrides = {}, seedCharges = [],
  seedInvoices = [], seedInvoiceLines = [],
} = {}) {
  return makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1, {
      status: 'signed', endedAt: '2026-03-02T10:30:00Z', signedAt: '2026-03-02T10:30:00Z', ...encounterOverrides,
    })],
    seedAppointments: [fullAppointment(APPT_A1, { serviceId: SVC_A1, ...appointmentOverrides })],
    seedCharges,
    seedInvoices,
    seedInvoiceLines,
  });
}

await test('encounters:invoice — the signed encounter issues a 201 with the exact Laravel invoice shape', async () => {
  const deps = invoiceFixtureDeps();
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), INVOICE_KEYS.slice().sort());
  assert.equal(body.data.id.length, 36);
  assert.match(body.data.invoiceNumber, /^INV-\d{8}-\d{5}$/);
  assert.equal(body.data.status, 'issued');
  // Consultation 50000 + prescription lines 500 + 3600 + 250 = 54350.
  assert.equal(body.data.totalMinor, 54350);
  assert.equal(body.data.totalTaxMinor, 0);
  assert.equal(body.data.paidMinor, 0);
  assert.equal(body.data.lines.length, 4);
  for (const line of body.data.lines) {
    assert.deepEqual(Object.keys(line).sort(), INVOICE_LINE_KEYS.slice().sort());
    assert.equal(line.taxMinor, 0);
  }
  assert.deepEqual(
    body.data.lines.map((line) => line.amountMinor).sort((a, b) => a - b),
    [250, 500, 3600, 50000],
  );
  assert.deepEqual(
    body.data.lines.map((line) => line.description).sort(),
    ['General OPD — consultation', 'Paracetamol (500mg) × 1', 'Paracetamol (500mg) × 2', 'Amoxicillin (250mg) × 3'].sort(),
  );
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // Stored invoice: server-derived number, issued status, tenant-bound.
  const stored = deps.getInvoices()[0];
  assert.equal(stored.status, 'issued');
  assert.equal(stored.totalMinor, 54350);
  assert.equal(stored.tenantId, 'org-a');
  assert.equal(stored.facilityId, 'fac-a1');
  assert.equal(deps.getInvoiceLines().length, 4);
  assert.equal(deps.getCharges().length, 4);
});

await test('encounters:invoice — the consultation charge is idempotent (never duplicated)', async () => {
  const deps = invoiceFixtureDeps({
    seedCharges: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000f07', tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: null, sourceType: 'encounter',
      description: 'General OPD — consultation', amountMinor: 10000, currency: 'NPR', taxRateBps: 0, status: 'posted',
    }],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  // Seeded consultation 10000 (NOT re-derived at 50000) + prescription 4350.
  assert.equal(body.data.totalMinor, 14350);
  assert.equal(deps.getCharges().filter((c) => c.sourceType === 'encounter').length, 1);
});

await test('encounters:invoice — an already-charged prescription is skipped (Laravel parity)', async () => {
  const deps = invoiceFixtureDeps({
    seedCharges: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000f08', tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: RX_A1, sourceType: 'prescription',
      description: 'Paracetamol (500mg) × 2', amountMinor: 500, currency: 'NPR', taxRateBps: 0, status: 'posted',
    }],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 201);
  // Consultation 50000 + the single existing prescription charge 500.
  assert.equal((await bodyJson(response)).data.totalMinor, 50500);
  assert.equal(deps.getCharges().filter((c) => c.sourceType === 'prescription').length, 1);
});

await test('encounters:invoice — a service with no default charge contributes nothing (Laravel parity)', async () => {
  const deps = invoiceFixtureDeps({ appointmentOverrides: { serviceId: SVC_A1_NULL_CHARGE } });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 201);
  // Prescription charges only: 500 + 3600 + 250 = 4350.
  assert.equal((await bodyJson(response)).data.totalMinor, 4350);
  assert.equal(deps.getCharges().filter((c) => c.sourceType === 'encounter').length, 0);
});

await test('encounters:invoice — posted manual charges with tax are included with exact tax math', async () => {
  const deps = invoiceFixtureDeps({
    seedCharges: [{
      id: CHARGE_MANUAL, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: null, sourceType: 'manual',
      description: 'Procedure pack', amountMinor: 1000, currency: 'NPR', taxRateBps: 1300, status: 'posted',
    }],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.equal(body.data.totalMinor, 54350 + 1000);
  // round(1000 * 1300 / 10000) = 130 — integer minor-unit tax math.
  assert.equal(body.data.totalTaxMinor, 130);
  const manual = body.data.lines.find((line) => line.description === 'Procedure pack');
  assert.deepEqual(manual, { description: 'Procedure pack', amountMinor: 1000, taxMinor: 130 });
});

await test('encounters:invoice — unauthenticated request is rejected (401)', async () => {
  const response = await handleEncountersInvoice(invoiceReq(ENC_A1), invoiceFixtureDeps());
  assert.equal(response.status, 401);
});

await test('encounters:invoice — an invalid token is rejected (401)', async () => {
  const response = await handleEncountersInvoice(
    invoiceReq(ENC_A1, { Authorization: 'Bearer not-a-jwt' }),
    invoiceFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:invoice — an expired token is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleEncountersInvoice(
    invoiceReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    invoiceFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('encounters:invoice — an unknown subject is rejected (401)', async () => {
  const response = await invoiceAs(SUB_UNKNOWN, ENC_A1, {}, '', invoiceFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:invoice — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await invoiceAs(SUB_LOCKED, ENC_A1, {}, '', invoiceFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:invoice — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await invoiceAs(SUB_DISABLED, ENC_A1, {}, '', invoiceFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:invoice — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-billing-clerk', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'facility',
      role: { id: 'r-billing-clerk', code: 'billing_clerk', scopeType: 'facility', permissions: permissions.billingClerk },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await invoiceAs(SUB_SUSPENDED, ENC_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounters:invoice fails closed with no context (no assignment)', async () => {
  const response = await invoiceAs(SUB_NO_ASSIGNMENT, ENC_A1, {}, '', invoiceFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:invoice denies a missing capability (403 SCOPE_DENIED, zero mutation)', async () => {
  // The doctor role has billing:view but NOT billing:invoice — exactly the
  // RolePermissionSeeder boundary.
  const deps = invoiceFixtureDeps();
  const response = await invoiceAs(SUB_FAC_DOCTOR, ENC_A1, {}, '', deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  assert.equal(deps.getInvoices().length, 0);
  assert.equal(deps.getCharges().length, 0);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('encounters:invoice — a malformed encounter id is 404 (binding parity)', async () => {
  for (const bad of ['not-a-uuid', '', 'abc']) {
    const response = await invoiceAs(SUB_CASHIER, bad);
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('encounters:invoice — a nonexistent encounter is 404', async () => {
  const response = await invoiceAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, '', invoiceFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:invoice — an out-of-scope encounter is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 })],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A2_OTHER_FAC, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:invoice — a cross-tenant encounter is 404', async () => {
  const deps = makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 })],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_B1, {}, '', deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:invoice — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-a2' }, '', invoiceFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounters:invoice — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await invoiceAs(
    SUB_CASHIER, ENC_A1,
    { 'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b' },
    '', invoiceFixtureDeps(),
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounters:invoice — forged app_* claims are inert (scope stays authoritative)', async () => {
  const deps = invoiceFixtureDeps();
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncountersInvoice(
    invoiceReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 201);
  assert.equal((await bodyJson(response)).meta.context.tenantId, 'org-a');
  assert.equal(deps.getInvoices()[0].tenantId, 'org-a');
});

await test('encounters:invoice — only a signed encounter can be billed (409, exact Laravel message)', async () => {
  const deps = invoiceFixtureDeps({ encounterOverrides: { status: 'open' } });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'Only a signed encounter can be billed.');
  assert.equal(deps.getInvoices().length, 0);
});

await test('encounters:invoice — an encounter with no charges cannot be billed (409, exact Laravel message)', async () => {
  const deps = invoiceFixtureDeps({
    encounterOverrides: { id: ENC_A1_SIGNED },
    appointmentOverrides: { serviceId: SVC_A1_NULL_CHARGE },
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1_SIGNED, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'This encounter has no charges to bill.');
  assert.equal(deps.getInvoices().length, 0);
});

await test('encounters:invoice — an already-invoiced charge is rejected (409, exact Laravel message)', async () => {
  const deps = invoiceFixtureDeps({
    seedCharges: [{
      id: CHARGE_MANUAL, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: null, sourceType: 'manual',
      description: 'Procedure pack', amountMinor: 1000, currency: 'NPR', taxRateBps: 0, status: 'posted',
    }],
    seedInvoices: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000g01', tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      invoiceNumber: 'INV-20260302-00001', status: 'issued', totalMinor: 1000, totalTaxMinor: 0, paidMinor: 0, lockVersion: 0,
    }],
    seedInvoiceLines: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000h01', tenantId: 'org-a', invoiceId: 'aaaaaaaa-0000-4000-8000-000000000g01',
      chargeId: CHARGE_MANUAL, description: 'Procedure pack', amountMinor: 1000, taxMinor: 0, lineNo: 1,
    }],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'One or more charges have already been invoiced.');
  assert.equal(deps.getInvoices().length, 1);
});

await test('encounters:invoice — a concurrent duplicate issue produces exactly one invoice (unique-index backstop)', async () => {
  const deps = invoiceFixtureDeps();
  const first = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  const second = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(first.status, 201);
  assert.equal(second.status, 409);
  const secondBody = await bodyJson(second);
  assert.equal(secondBody.error.message, 'One or more charges have already been invoiced.');
  assert.equal(deps.getInvoices().length, 1);
  assert.equal(deps.getInvoiceLines().length, 4);
});

await test('encounters:invoice — a server-side invoice-number collision is a retryable 409', async () => {
  const deps = invoiceFixtureDeps({
    seedCharges: [{
      id: CHARGE_MANUAL, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: null, sourceType: 'manual',
      description: 'Procedure pack', amountMinor: 1000, currency: 'NPR', taxRateBps: 0, status: 'posted',
    }],
    seedInvoices: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000g01', tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      invoiceNumber: 'INV-20260302-10001', status: 'issued', totalMinor: 1000, totalTaxMinor: 0, paidMinor: 0, lockVersion: 0,
    }],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'The invoice number collided with a concurrent issue. Retry.');
  assert.equal(deps.getInvoices().length, 1);
  assert.equal(deps.getCharges().length, 1);
});

await test('encounters:invoice — a failed issue leaves NO partial charges or invoice (rollback parity)', async () => {
  const deps = invoiceFixtureDeps({
    seedCharges: [{
      id: CHARGE_MANUAL, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: null, sourceType: 'manual',
      description: 'Procedure pack', amountMinor: 1000, currency: 'NPR', taxRateBps: 0, status: 'posted',
    }],
    seedInvoices: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000g01', tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      invoiceNumber: 'INV-20260302-00001', status: 'issued', totalMinor: 1000, totalTaxMinor: 0, paidMinor: 0, lockVersion: 0,
    }],
    seedInvoiceLines: [{
      id: 'aaaaaaaa-0000-4000-8000-000000000h01', tenantId: 'org-a', invoiceId: 'aaaaaaaa-0000-4000-8000-000000000g01',
      chargeId: CHARGE_MANUAL, description: 'Procedure pack', amountMinor: 1000, taxMinor: 0, lineNo: 1,
    }],
  });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  // The consultation + prescription charges derived INSIDE the failed
  // transaction were rolled back — only the seeded manual charge remains.
  assert.equal(deps.getCharges().length, 1);
  assert.equal(deps.getInvoices().length, 1);
  assert.equal(deps.getInvoiceLines().length, 1);
});

await test('encounters:invoice — the strict no-body contract rejects every forged billing field (422 NOT_ALLOWED)', async () => {
  for (const forged of [
    { invoiceNumber: 'INV-99999999-99999' },
    { totalMinor: 1 },
    { amountMinor: 1 },
    { status: 'paid' },
    { tenantId: 'org-b' },
    { facilityId: 'fac-b' },
    { patientId: 'ffffffff-0000-4000-8000-000000000000' },
    { encounterId: 'ffffffff-0000-4000-8000-000000000000' },
    { lines: [] },
  ]) {
    const deps = invoiceFixtureDeps();
    const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, forged, deps);
    assert.equal(response.status, 422, JSON.stringify(forged));
    const body = await bodyJson(response);
    assert.equal(body.error.code, 'VALIDATION_ERROR', JSON.stringify(forged));
    assert.equal(body.error.details[0].code, 'NOT_ALLOWED', JSON.stringify(forged));
    assert.equal(body.error.details[0].field, Object.keys(forged)[0], JSON.stringify(forged));
    assert.equal(deps.getInvoices().length, 0);
    assert.equal(deps.getCharges().length, 0);
  }
});

await test('encounters:invoice — malformed JSON is rejected (400 INVALID_REQUEST)', async () => {
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '{not-json', invoiceFixtureDeps());
  assert.equal(response.status, 400);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_REQUEST');
});

await test('encounters:invoice — audit records invoice.issued exactly once with authoritative attribution', async () => {
  const deps = invoiceFixtureDeps();
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-inv-1' }, '', deps);
  assert.equal(response.status, 201);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'invoice.issued');
  assert.equal(events[0].resourceType, 'invoice');
  assert.equal(events[0].resourceId, deps.getInvoices()[0].id);
  assert.equal(events[0].actorId, 'u-cashier');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-inv-1');
  assert.deepEqual(events[0].payload, {
    patientId: PAT_A1, encounterId: ENC_A1, totalMinor: 54350, lineCount: 4,
  });
});

await test('encounters:invoice — no audit event is recorded on a failed issue', async () => {
  const deps = invoiceFixtureDeps({ encounterOverrides: { status: 'open' } });
  const response = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  assert.equal(response.status, 409);
  assert.equal(deps.getAuditEvents().filter((e) => e.action === 'invoice.issued').length, 0);
});

await test('encounters:invoice — no secret/token/hash/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER });
  const deps = invoiceFixtureDeps();
  const ok = await invoiceAs(SUB_CASHIER, ENC_A1, {}, '', deps);
  const err = await handleEncountersInvoice(invoiceReq(ENC_A1), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
    assert.ok(!text.includes('tax_rate_bps'), 'internal charge fields must not appear');
  }
});

await test('encounters:invoice — correlation-id propagation contract', async () => {
  const deps = invoiceFixtureDeps();
  const ok = await invoiceAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-inv-2' }, '', deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-inv-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-inv-2');
  const err = await invoiceAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-inv-3' }, { x: 1 }, deps);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-inv-3');
});

/* ------------------------------------------------------------------ */
/* 18. invoices:pay (Phase 16 — payment capture / idempotency)          */
/* ------------------------------------------------------------------ */

const INV_A1 = 'aaaaaaaa-0000-4000-8000-000000000c10';
const INV_A1_PAID = 'aaaaaaaa-0000-4000-8000-000000000c11';
const INV_A1_VOIDED = 'aaaaaaaa-0000-4000-8000-000000000c12';
const INV_A2_OTHER_FAC = 'aaaaaaaa-0000-4000-8000-000000000c13';
const INV_B1 = 'aaaaaaaa-0000-4000-8000-000000000c14';

/** A full InvoicePayRow-shaped seed (an issued, unpaid invoice). */
function fullInvoice(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
    invoiceNumber: 'INV-20260302-10010', status: 'issued',
    totalMinor: 54350, totalTaxMinor: 0, paidMinor: 0, lockVersion: 0,
    issuedAt: '2026-03-02T09:00:00Z',
    ...overrides,
  };
}

function payReq(id, headers = {}, body) {
  return new Request(`https://example.supabase.co/functions/v1/invoices-pay/${id}`, {
    method: 'POST',
    headers: { ...headers },
    body: body === undefined ? undefined : (typeof body === 'string' ? body : JSON.stringify(body)),
  });
}

async function payAs(sub, id, body, headers = {}, deps = makeDeps()) {
  return handleInvoicesPay(
    payReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }, body),
    deps,
  );
}

// A valid capture body (CapturePaymentRequest parity).
const PAY_BODY = { method: 'cash', amountMinor: 54350, idempotencyKey: 'pay-key-0001' };

// The exact BillingController::pay data key sets.
const PAY_KEYS = ['paymentId', 'status', 'amountMinor', 'method', 'replayed', 'invoice'];
const PAY_INVOICE_KEYS = ['id', 'invoiceNumber', 'status', 'totalMinor', 'paidMinor'];

// A fully eligible fixture set: an issued, unpaid invoice (org-a / fac-a1).
function payFixtureDeps({ invoiceOverrides = {}, seedPayments = [], seedAllocations = [] } = {}) {
  return makeDeps({}, {
    seedInvoices: [fullInvoice(INV_A1, invoiceOverrides)],
    seedPayments,
    seedAllocations,
  });
}

await test('invoices:pay — a full capture against the issued invoice returns 201 with the exact Laravel shape', async () => {
  const deps = payFixtureDeps();
  const response = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  assert.equal(response.status, 201);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), PAY_KEYS.slice().sort());
  assert.deepEqual(Object.keys(body.data.invoice).sort(), PAY_INVOICE_KEYS.slice().sort());
  assert.equal(body.data.paymentId.length, 36);
  assert.equal(body.data.status, 'captured');
  assert.equal(body.data.amountMinor, 54350);
  assert.equal(body.data.method, 'cash');
  assert.equal(body.data.replayed, false);
  assert.equal(body.data.invoice.id, INV_A1);
  assert.equal(body.data.invoice.invoiceNumber, 'INV-20260302-10010');
  assert.equal(body.data.invoice.status, 'paid');
  assert.equal(body.data.invoice.totalMinor, 54350);
  assert.equal(body.data.invoice.paidMinor, 54350);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // Stored payment: server-derived received_at + actor attribution.
  const stored = deps.getPayments()[0];
  assert.equal(stored.status, 'captured');
  assert.equal(stored.currency, 'NPR');
  assert.equal(stored.receivedBy, 'u-cashier');
  assert.equal(stored.idempotencyKey, 'pay-key-0001');
  assert.ok(stored.receivedAt);
  // Allocation + guarded invoice update landed in the same commit.
  assert.equal(deps.getAllocations().length, 1);
  assert.equal(deps.getAllocations()[0].amountMinor, 54350);
  assert.equal(deps.getInvoices()[0].status, 'paid');
  assert.equal(deps.getInvoices()[0].paidMinor, 54350);
  assert.equal(deps.getInvoices()[0].lockVersion, 1);
});

await test('invoices:pay — a partial capture leaves the invoice partially_paid; a second key completes it', async () => {
  const deps = payFixtureDeps();
  const first = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, amountMinor: 20000 }, {}, deps);
  assert.equal(first.status, 201);
  const firstBody = await bodyJson(first);
  assert.equal(firstBody.data.invoice.status, 'partially_paid');
  assert.equal(firstBody.data.invoice.paidMinor, 20000);
  const second = await payAs(SUB_CASHIER, INV_A1, { method: 'cash', amountMinor: 34350, idempotencyKey: 'pay-key-0002' }, {}, deps);
  assert.equal(second.status, 201);
  const secondBody = await bodyJson(second);
  assert.equal(secondBody.data.invoice.status, 'paid');
  assert.equal(secondBody.data.invoice.paidMinor, 54350);
  assert.equal(deps.getPayments().length, 2);
  assert.equal(deps.getAllocations().length, 2);
  assert.equal(deps.getInvoices()[0].lockVersion, 2);
});

await test('invoices:pay — an identical idempotency-key request REPLAYS the payment (200, no new money)', async () => {
  const deps = payFixtureDeps();
  const first = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  const second = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  assert.equal(first.status, 201);
  assert.equal(second.status, 200);
  const firstBody = await bodyJson(first);
  const secondBody = await bodyJson(second);
  assert.equal(secondBody.data.replayed, true);
  assert.equal(secondBody.data.paymentId, firstBody.data.paymentId);
  assert.equal(secondBody.data.invoice.paidMinor, 54350);
  // Exactly one payment + one allocation — the replay created NO new money.
  assert.equal(deps.getPayments().length, 1);
  assert.equal(deps.getAllocations().length, 1);
  assert.equal(deps.getInvoices()[0].paidMinor, 54350);
});

await test('invoices:pay — a replay SKIPS the eligibility checks (Laravel parity: idempotency first)', async () => {
  const deps = payFixtureDeps();
  await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps); // invoice now paid
  const replay = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  assert.equal(replay.status, 200);
  assert.equal((await bodyJson(replay)).data.replayed, true);
  assert.equal(deps.getPayments().length, 1);
  assert.equal(deps.getInvoices()[0].status, 'paid');
});

await test('invoices:pay — validation: missing method (422 REQUIRED)', async () => {
  const deps = payFixtureDeps();
  const response = await payAs(SUB_CASHIER, INV_A1, { amountMinor: 1000, idempotencyKey: 'pay-key-0003' }, {}, deps);
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'method');
  assert.equal(body.error.details[0].code, 'REQUIRED');
  assert.equal(body.error.details[0].message, 'The method field is required.');
});

await test('invoices:pay — validation: an invalid method is rejected (422 INVALID_VALUE)', async () => {
  const response = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, method: 'crypto' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.details[0].field, 'method');
  assert.equal(body.error.details[0].code, 'INVALID_VALUE');
  assert.equal(body.error.details[0].message, 'The selected method is invalid.');
});

await test('invoices:pay — validation: missing amountMinor (422 REQUIRED)', async () => {
  const response = await payAs(SUB_CASHIER, INV_A1, { method: 'cash', idempotencyKey: 'pay-key-0004' });
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.details[0].field, 'amountMinor');
  assert.equal(body.error.details[0].code, 'REQUIRED');
});

await test('invoices:pay — validation: non-positive / non-integer amounts are rejected (422 INVALID_VALUE)', async () => {
  for (const amountMinor of [0, -5, 1.5, 'ten']) {
    const response = await payAs(SUB_CASHIER, INV_A1, { method: 'cash', amountMinor, idempotencyKey: 'pay-key-0005' });
    assert.equal(response.status, 422, `amount ${amountMinor}`);
    const body = await bodyJson(response);
    assert.equal(body.error.details[0].field, 'amountMinor', `amount ${amountMinor}`);
    assert.equal(body.error.details[0].code, 'INVALID_VALUE', `amount ${amountMinor}`);
  }
});

await test('invoices:pay — validation: idempotencyKey is required and bounded 8..100 (422)', async () => {
  const missing = await payAs(SUB_CASHIER, INV_A1, { method: 'cash', amountMinor: 1000 });
  assert.equal(missing.status, 422);
  const missingBody = await bodyJson(missing);
  assert.equal(missingBody.error.details[0].field, 'idempotencyKey');
  assert.equal(missingBody.error.details[0].code, 'REQUIRED');
  const short = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, idempotencyKey: 'short' });
  assert.equal(short.status, 422);
  assert.equal((await bodyJson(short)).error.details[0].message, 'The idempotency key must be at least 8 characters.');
  const long = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, idempotencyKey: 'k'.repeat(101) });
  assert.equal(long.status, 422);
  assert.equal((await bodyJson(long)).error.details[0].message, 'The idempotency key must be at most 100 characters.');
});

await test('invoices:pay — validation: providerRef is optional and bounded to 100 (422)', async () => {
  const ok = payFixtureDeps();
  const good = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, providerRef: 'gateway-ref-1' }, {}, ok);
  assert.equal(good.status, 201);
  assert.equal(ok.getPayments()[0].providerRef, 'gateway-ref-1');
  const bad = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, providerRef: 'r'.repeat(101) }, {}, payFixtureDeps());
  assert.equal(bad.status, 422);
  const badBody = await bodyJson(bad);
  assert.equal(badBody.error.details[0].field, 'providerRef');
  assert.equal(badBody.error.details[0].message, 'The provider ref must be at most 100 characters.');
});

await test('invoices:pay — validation: client-forged scope/status/lock fields are rejected (422 NOT_ALLOWED)', async () => {
  for (const forged of [
    { tenantId: 'org-b' },
    { facilityId: 'fac-b' },
    { branchId: 'br-b' },
    { lockVersion: 0 },
    { invoiceStatus: 'paid' },
    { receivedBy: 'u-attacker' },
    { allocatedBy: 'u-attacker' },
    { status: 'paid' },
  ]) {
    const deps = payFixtureDeps();
    const response = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, ...forged }, {}, deps);
    assert.equal(response.status, 422, JSON.stringify(forged));
    const body = await bodyJson(response);
    assert.equal(body.error.details[0].field, Object.keys(forged)[0], JSON.stringify(forged));
    assert.equal(body.error.details[0].code, 'NOT_ALLOWED', JSON.stringify(forged));
    assert.equal(deps.getPayments().length, 0, JSON.stringify(forged));
  }
});

await test('invoices:pay — malformed JSON is rejected (400 INVALID_REQUEST)', async () => {
  const response = await payAs(SUB_CASHIER, INV_A1, '{not-json', {}, payFixtureDeps());
  assert.equal(response.status, 400);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_REQUEST');
});

await test('invoices:pay — an empty body is rejected (422 REQUIRED)', async () => {
  const response = await payAs(SUB_CASHIER, INV_A1, '', {}, payFixtureDeps());
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.details[0].field, 'body');
  assert.equal(body.error.details[0].code, 'REQUIRED');
});

await test('invoices:pay — an overpayment is rejected with the exact Laravel balance message (422)', async () => {
  const deps = payFixtureDeps();
  const response = await payAs(SUB_CASHIER, INV_A1, { ...PAY_BODY, amountMinor: 60000 }, {}, deps);
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.equal(body.error.message, 'Payment of 60000 exceeds the outstanding balance of 54350.');
  assert.equal(deps.getPayments().length, 0);
  assert.equal(deps.getInvoices()[0].paidMinor, 0);
});

await test('invoices:pay — unauthenticated request is rejected (401)', async () => {
  const response = await handleInvoicesPay(payReq(INV_A1, {}, PAY_BODY), payFixtureDeps());
  assert.equal(response.status, 401);
});

await test('invoices:pay — an invalid token is rejected (401 INVALID_TOKEN)', async () => {
  const response = await handleInvoicesPay(
    payReq(INV_A1, { Authorization: 'Bearer not-a-jwt' }, PAY_BODY),
    payFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('invoices:pay — an expired token is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleInvoicesPay(
    payReq(INV_A1, { Authorization: `Bearer ${token}` }, PAY_BODY),
    payFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('invoices:pay — an unknown subject is rejected (401 INVALID_TOKEN)', async () => {
  const response = await payAs(SUB_UNKNOWN, INV_A1, PAY_BODY, {}, payFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('invoices:pay — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await payAs(SUB_LOCKED, INV_A1, PAY_BODY, {}, payFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:pay — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await payAs(SUB_DISABLED, INV_A1, PAY_BODY, {}, payFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:pay — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_SUSPENDED ? suspendedAdmin : null),
    loadActiveAssignments: () => [{
      id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-billing-clerk', tenantId: 'org-suspended',
      facilityId: null, branchId: null, scopeType: 'facility',
      role: { id: 'r-billing-clerk', code: 'billing_clerk', scopeType: 'facility', permissions: permissions.billingClerk },
    }],
    loadOrganization: () => orgSuspended,
  });
  const response = await payAs(SUB_SUSPENDED, INV_A1, PAY_BODY, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('invoices:pay fails closed with no context (no assignment)', async () => {
  const response = await payAs(SUB_NO_ASSIGNMENT, INV_A1, PAY_BODY, {}, payFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:pay denies a missing capability (403 SCOPE_DENIED, zero mutation)', async () => {
  // The doctor role has billing:view but NOT billing:collect — exactly the
  // RolePermissionSeeder boundary.
  const deps = payFixtureDeps();
  const response = await payAs(SUB_FAC_DOCTOR, INV_A1, PAY_BODY, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
  assert.equal(deps.getPayments().length, 0);
  assert.equal(deps.getAllocations().length, 0);
  assert.equal(deps.getInvoices()[0].paidMinor, 0);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('invoices:pay — a malformed invoice id is 404 (binding parity)', async () => {
  for (const bad of ['not-a-uuid', '', 'abc']) {
    const response = await payAs(SUB_CASHIER, bad, PAY_BODY);
    assert.equal(response.status, 404, `id ${bad}`);
    assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND', `id ${bad}`);
  }
});

await test('invoices:pay — a nonexistent invoice is 404', async () => {
  const response = await payAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', PAY_BODY, {}, payFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:pay — an out-of-scope invoice is 404 (existence never leaked)', async () => {
  const deps = makeDeps({}, { seedInvoices: [fullInvoice(INV_A2_OTHER_FAC, { facilityId: 'fac-a2' })] });
  const response = await payAs(SUB_CASHIER, INV_A2_OTHER_FAC, PAY_BODY, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:pay — a cross-tenant invoice is 404', async () => {
  const deps = makeDeps({}, { seedInvoices: [fullInvoice(INV_B1, { tenantId: 'org-b', facilityId: 'fac-b' })] });
  const response = await payAs(SUB_CASHIER, INV_B1, PAY_BODY, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:pay — facility proposal cannot expand scope (403 FACILITY_DENIED)', async () => {
  const response = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, { 'X-Swasthya-Facility': 'fac-a2' }, payFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('invoices:pay — branch proposal cannot expand scope (403 BRANCH_DENIED)', async () => {
  const response = await payAs(
    SUB_CASHIER, INV_A1, PAY_BODY,
    { 'X-Swasthya-Facility': 'fac-a1', 'X-Swasthya-Branch': 'br-b' },
    payFixtureDeps(),
  );
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('invoices:pay — forged app_* claims are inert (scope stays authoritative)', async () => {
  const deps = payFixtureDeps();
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleInvoicesPay(
    payReq(INV_A1, { Authorization: `Bearer ${token}` }, PAY_BODY),
    deps,
  );
  assert.equal(response.status, 201);
  assert.equal((await bodyJson(response)).meta.context.tenantId, 'org-a');
  assert.equal(deps.getInvoices()[0].tenantId, 'org-a');
  assert.equal(deps.getInvoices()[0].paidMinor, 54350);
});

await test('invoices:pay — a voided invoice cannot be paid (409, exact Laravel message)', async () => {
  const deps = payFixtureDeps({ invoiceOverrides: { status: 'voided' } });
  const response = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'A voided invoice cannot be paid.');
  assert.equal(deps.getPayments().length, 0);
});

await test('invoices:pay — an already-paid invoice is rejected (409, exact Laravel message)', async () => {
  const deps = payFixtureDeps({ invoiceOverrides: { status: 'paid', paidMinor: 54350, lockVersion: 1 } });
  const response = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  assert.equal(response.status, 409);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'CONFLICT');
  assert.equal(body.error.message, 'This invoice is already paid.');
  assert.equal(deps.getPayments().length, 0);
  assert.equal(deps.getInvoices()[0].lockVersion, 1);
});

await test('invoices:pay — a stale lock_version (concurrent loser) is a 409 LOCK_CONFLICT with full rollback', async () => {
  const deps = payFixtureDeps();
  // The winner captures a PARTIAL payment (invoice stays eligible, so the
  // loser reaches the optimistic-lock guard — Laravel parity).
  const first = await payAs(SUB_CASHIER, INV_A1, { method: 'cash', amountMinor: 20000, idempotencyKey: 'pay-key-0002' }, {}, deps);
  assert.equal(first.status, 201);
  // The concurrent loser's pre-transaction read observed lock_version 0
  // (paidMinor 0) BEFORE the winner committed; its guarded update then
  // matches zero rows.
  const stale = deps.getInvoices()[0];
  const staleDeps = {
    ...deps,
    findInvoiceByScope: () => ({
      id: stale.id, facilityId: stale.facilityId, patientId: stale.patientId,
      invoiceNumber: stale.invoiceNumber, status: 'issued', totalMinor: stale.totalMinor,
      totalTaxMinor: stale.totalTaxMinor, paidMinor: 0, lockVersion: 0,
    }),
  };
  const second = await payAs(SUB_CASHIER, INV_A1, { method: 'cash', amountMinor: 20000, idempotencyKey: 'pay-key-0003' }, {}, staleDeps);
  assert.equal(second.status, 409);
  const body = await bodyJson(second);
  assert.equal(body.error.code, 'LOCK_CONFLICT');
  assert.equal(body.error.message, 'This invoice was changed by another payment. Reload and retry.');
  // ROLLBACK: the loser's payment + allocation were never committed.
  assert.equal(deps.getPayments().length, 1);
  assert.equal(deps.getAllocations().length, 1);
  assert.equal(deps.getInvoices()[0].lockVersion, 1);
  assert.equal(deps.getInvoices()[0].paidMinor, 20000);
  assert.equal(deps.getInvoices()[0].status, 'partially_paid');
});

await test('invoices:pay — a failed capture records NO payment and NO audit (rollback parity)', async () => {
  const deps = payFixtureDeps({ invoiceOverrides: { status: 'voided' } });
  const response = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  assert.equal(response.status, 409);
  assert.equal(deps.getPayments().length, 0);
  assert.equal(deps.getAllocations().length, 0);
  assert.equal(deps.getAuditEvents().filter((e) => e.action === 'payment.captured' || e.action === 'payment.replayed').length, 0);
});

await test('invoices:pay — audit records payment.captured exactly once with authoritative attribution', async () => {
  const deps = payFixtureDeps();
  const response = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, { 'X-Correlation-Id': 'corr-pay-1' }, deps);
  assert.equal(response.status, 201);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'payment.captured');
  assert.equal(events[0].resourceType, 'payment');
  assert.equal(events[0].resourceId, deps.getPayments()[0].id);
  assert.equal(events[0].actorId, 'u-cashier');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-pay-1');
  assert.deepEqual(events[0].payload, {
    invoiceId: INV_A1, method: 'cash', amountMinor: 54350, replayed: false,
  });
});

await test('invoices:pay — a replay records payment.replayed (Laravel parity)', async () => {
  const deps = payFixtureDeps();
  await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  const events = deps.getAuditEvents();
  assert.equal(events.filter((e) => e.action === 'payment.captured').length, 1);
  const replayed = events.find((e) => e.action === 'payment.replayed');
  assert.ok(replayed, 'a replay must be audited');
  assert.deepEqual(replayed.payload, {
    invoiceId: INV_A1, method: 'cash', amountMinor: 54350, replayed: true,
  });
});

await test('invoices:pay — no secret/token/hash/idempotency-key/audit internals leak in any response', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER });
  const deps = payFixtureDeps();
  const ok = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, {}, deps);
  const err = await handleInvoicesPay(payReq(INV_A1, {}, PAY_BODY), makeDeps());
  for (const response of [ok, err]) {
    const text = await response.text();
    assert.ok(!text.includes(SECRET), 'JWT secret must not appear');
    assert.ok(!text.includes(token), 'bearer token must not appear');
    assert.ok(!text.toLowerCase().includes('password'), 'password material must not appear');
    assert.ok(!text.includes('permission'), 'permissions must not appear');
    assert.ok(!text.includes('assignment'), 'assignments must not appear');
    assert.ok(!text.includes('audit'), 'audit internals must not appear');
    assert.ok(!text.includes('prev_hash'), 'audit chain internals must not appear');
    assert.ok(!text.includes('idempotency'), 'the idempotency key must not appear');
    assert.ok(!text.includes('lock_version'), 'lock_version must not appear');
  }
});

await test('invoices:pay — correlation-id propagation contract', async () => {
  const deps = payFixtureDeps();
  const ok = await payAs(SUB_CASHIER, INV_A1, PAY_BODY, { 'X-Correlation-Id': 'corr-pay-2' }, deps);
  assert.equal(ok.headers.get('X-Request-Id'), 'corr-pay-2');
  assert.equal(ok.headers.get('X-Correlation-Id'), 'corr-pay-2');
  const errDeps = payFixtureDeps();
  const err = await payAs(
    SUB_CASHIER, INV_A1,
    { method: 'cash', amountMinor: 60000, idempotencyKey: 'pay-key-0009' },
    { 'X-Correlation-Id': 'corr-pay-3' },
    errDeps,
  );
  assert.equal(err.status, 422);
  assert.equal((await bodyJson(err)).error.correlationId, 'corr-pay-3');
});

/* ================================================================== */
/* PHASE 17 — invoices:show (single-invoice READ)                     */
/* ================================================================== */

const INV_LINE_1 = 'aaaaaaaa-0000-4000-8000-000000000d01';
const INV_LINE_2 = 'aaaaaaaa-0000-4000-8000-000000000d02';

function invoiceShowReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/invoices-show/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function invoiceShowAs(sub, id, headers = {}, deps = makeDeps()) {
  return handleInvoicesShow(
    invoiceShowReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

/** A full eligible read fixture: an issued invoice with two lines (org-a /
 * fac-a1), presented ordered by line_no exactly as presentInvoice. */
function showFixtureDeps({ invoiceOverrides = {}, seedLines = true } = {}) {
  return makeDeps({}, {
    seedInvoices: [fullInvoice(INV_A1, invoiceOverrides)],
    seedInvoiceLines: seedLines
      ? [
        // Deliberately out of order in the store — the read must order by
        // line_no (Invoice::lines() parity).
        { id: INV_LINE_2, tenantId: 'org-a', invoiceId: INV_A1, chargeId: 'ch-2',
          description: 'Paracetamol 500mg x 2', amountMinor: 500, taxMinor: 0, lineNo: 2 },
        { id: INV_LINE_1, tenantId: 'org-a', invoiceId: INV_A1, chargeId: 'ch-1',
          description: 'OPD consultation', amountMinor: 54350, taxMinor: 0, lineNo: 1 },
      ]
      : [],
  });
}

// The exact presentInvoice header + line key sets (BillingController
// ::presentInvoice — lines carry NO tenant/charge ids).
const SHOW_HEADER_KEYS = ['facilityId', 'id', 'invoiceNumber', 'issuedAt', 'lines', 'lockVersion', 'paidMinor', 'patientId', 'status', 'totalMinor', 'totalTaxMinor'];
const SHOW_LINE_KEYS = ['amountMinor', 'description', 'id', 'taxMinor'];

await test('invoices:show — a cashier reads an in-scope issued invoice (200, exact presentInvoice shape)', async () => {
  const deps = showFixtureDeps();
  const response = await invoiceShowAs(SUB_CASHIER, INV_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), SHOW_HEADER_KEYS.slice().sort());
  assert.equal(body.data.id, INV_A1);
  assert.equal(body.data.invoiceNumber, 'INV-20260302-10010');
  assert.equal(body.data.facilityId, 'fac-a1');
  assert.equal(body.data.patientId, PAT_A1);
  assert.equal(body.data.status, 'issued');
  assert.equal(body.data.totalMinor, 54350);
  assert.equal(body.data.totalTaxMinor, 0);
  assert.equal(body.data.paidMinor, 0);
  assert.equal(body.data.issuedAt, '2026-03-02T09:00:00Z');
  assert.equal(body.data.lockVersion, 0);
  assert.equal(body.data.lines.length, 2);
  // Ordered by line_no; only the approved line fields are exposed.
  assert.deepEqual(Object.keys(body.data.lines[0]).sort(), SHOW_LINE_KEYS.slice().sort());
  assert.equal(body.data.lines[0].id, INV_LINE_1);
  assert.equal(body.data.lines[0].description, 'OPD consultation');
  assert.equal(body.data.lines[0].amountMinor, 54350);
  assert.equal(body.data.lines[0].taxMinor, 0);
  assert.equal(body.data.lines[1].id, INV_LINE_2);
  assert.equal(body.data.lines[1].description, 'Paracetamol 500mg x 2');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('invoices:show — the read mutates nothing (status/paid/lock_version untouched, no rows created)', async () => {
  const deps = showFixtureDeps();
  await invoiceShowAs(SUB_CASHIER, INV_A1, {}, deps);
  const stored = deps.getInvoices()[0];
  assert.equal(stored.status, 'issued');
  assert.equal(stored.paidMinor, 0);
  assert.equal(stored.lockVersion, 0);
  assert.equal(deps.getPayments().length, 0);
  assert.equal(deps.getAllocations().length, 0);
  assert.equal(deps.getInvoiceLines().length, 2);
});

await test('invoices:show — an invoice with no lines returns an empty lines array', async () => {
  const deps = showFixtureDeps({ seedLines: false });
  const body = await bodyJson(await invoiceShowAs(SUB_CASHIER, INV_A1, {}, deps));
  assert.equal(body.data.lines.length, 0);
});

await test('invoices:show — paid/partially_paid/voided statuses pass through as stored', async () => {
  const paid = showFixtureDeps({ invoiceOverrides: { status: 'paid', paidMinor: 54350, lockVersion: 1 }, seedLines: false });
  const paidBody = await bodyJson(await invoiceShowAs(SUB_CASHIER, INV_A1, {}, paid));
  assert.equal(paidBody.data.status, 'paid');
  assert.equal(paidBody.data.paidMinor, 54350);
  assert.equal(paidBody.data.lockVersion, 1);

  const partial = showFixtureDeps({ invoiceOverrides: { status: 'partially_paid', paidMinor: 20000, lockVersion: 1 }, seedLines: false });
  const partialBody = await bodyJson(await invoiceShowAs(SUB_CASHIER, INV_A1, {}, partial));
  assert.equal(partialBody.data.status, 'partially_paid');
  assert.equal(partialBody.data.paidMinor, 20000);

  const voided = showFixtureDeps({ invoiceOverrides: { status: 'voided' }, seedLines: false });
  const voidedBody = await bodyJson(await invoiceShowAs(SUB_CASHIER, INV_A1, {}, voided));
  assert.equal(voidedBody.data.status, 'voided');
});

await test('invoices:show — a missing issuedAt renders as null (Laravel nullable parity)', async () => {
  const deps = showFixtureDeps({ invoiceOverrides: { issuedAt: null }, seedLines: false });
  const body = await bodyJson(await invoiceShowAs(SUB_CASHIER, INV_A1, {}, deps));
  assert.equal(body.data.issuedAt, null);
});

await test('invoices:show — missing Authorization is rejected (401)', async () => {
  const response = await handleInvoicesShow(invoiceShowReq(INV_A1), showFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('invoices:show — an invalid JWT is rejected (401)', async () => {
  const response = await handleInvoicesShow(
    invoiceShowReq(INV_A1, { Authorization: 'Bearer not-a-jwt' }),
    showFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('invoices:show — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleInvoicesShow(
    invoiceShowReq(INV_A1, { Authorization: `Bearer ${token}` }),
    showFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('invoices:show — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleInvoicesShow(
    invoiceShowReq(INV_A1, { Authorization: `Bearer ${token}` }),
    showFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('invoices:show — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await invoiceShowAs(SUB_LOCKED, INV_A1, {}, showFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:show — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await invoiceShowAs(SUB_DISABLED, INV_A1, {}, showFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:show — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await invoiceShowAs(SUB_CASHIER, INV_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('invoices:show — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await invoiceShowAs(SUB_NO_ASSIGNMENT, INV_A1, {}, showFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:show — a principal without billing:view is denied (403 SCOPE_DENIED) with zero audit', async () => {
  const deps = showFixtureDeps();
  const response = await invoiceShowAs(SUB_RECEPTIONIST, INV_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('invoices:show — a malformed invoice id is indistinguishable from a missing resource (404)', async () => {
  const response = await invoiceShowAs(SUB_CASHIER, 'not-a-uuid', {}, showFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('invoices:show — a nonexistent invoice returns 404 (existence never leaked)', async () => {
  const response = await invoiceShowAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, showFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('invoices:show — a cross-tenant invoice is invisible (404)', async () => {
  const deps = makeDeps({}, { seedInvoices: [fullInvoice(INV_B1, { tenantId: 'org-b', facilityId: 'fac-b' })], seedInvoiceLines: [] });
  const response = await invoiceShowAs(SUB_CASHIER, INV_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:show — a cross-facility invoice is invisible (404)', async () => {
  const deps = makeDeps({}, { seedInvoices: [fullInvoice(INV_A2_OTHER_FAC, { facilityId: 'fac-a2' })], seedInvoiceLines: [] });
  const response = await invoiceShowAs(SUB_CASHIER, INV_A2_OTHER_FAC, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:show — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  // The cashier is assigned only to fac-a1; `fac-b` is outside that set —
  // the proposal is a SELECTOR validated against active assignments and
  // cannot expand scope. Established Phase 15 convention: 403 FACILITY_DENIED.
  const deps = showFixtureDeps();
  const response = await invoiceShowAs(SUB_CASHIER, INV_A1, { 'X-Swasthya-Facility': 'fac-b' }, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('invoices:show — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  // br-b belongs to org-b/fac-b; the resolved context is org-a/fac-a1 —
  // the proposal cannot select a branch outside the authoritative context.
  const response = await invoiceShowAs(SUB_CASHIER, INV_A1, { 'X-Swasthya-Branch': 'br-b' }, showFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('invoices:show — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  // fac-a1 IS the cashier\'s assigned facility — the proposal selects the
  // same authoritative context and the read succeeds.
  const deps = showFixtureDeps();
  const response = await invoiceShowAs(SUB_CASHIER, INV_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.facilityId, 'fac-a1');
});

await test('invoices:show — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleInvoicesShow(
    invoiceShowReq(INV_A1, { Authorization: `Bearer ${token}` }),
    showFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // The authoritative context sees the org-a invoice — the forged claims
  // never change scope.
  assert.equal(body.data.id, INV_A1);
});

await test('invoices:show — the read records invoice.viewed exactly once with the exact Laravel payload', async () => {
  const deps = showFixtureDeps();
  await invoiceShowAs(SUB_CASHIER, INV_A1, { 'X-Correlation-Id': 'corr-show-1' }, deps);
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'invoice.viewed');
  assert.equal(events[0].resourceType, 'invoice');
  assert.equal(events[0].resourceId, INV_A1);
  assert.equal(events[0].actorId, 'u-cashier');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-show-1');
  // Exact Laravel payload: { patientId } — nothing else leaks.
  assert.deepEqual(events[0].payload, { patientId: PAT_A1 });
});

await test('invoices:show — a failed read records no audit event', async () => {
  const deps = showFixtureDeps();
  await invoiceShowAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, deps);
  assert.equal(deps.getAuditEvents().length, 0);
  const deniedDeps = showFixtureDeps();
  await invoiceShowAs(SUB_RECEPTIONIST, INV_A1, {}, deniedDeps);
  assert.equal(deniedDeps.getAuditEvents().length, 0);
});

await test('invoices:show — correlation id propagates to the response and the audit record', async () => {
  const deps = showFixtureDeps();
  const response = await invoiceShowAs(SUB_CASHIER, INV_A1, { 'X-Correlation-Id': 'corr-show-2' }, deps);
  assert.equal(response.headers.get('X-Request-Id'), 'corr-show-2');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-show-2');
  assert.equal((await bodyJson(response)).error === undefined, true);
  assert.equal(deps.getAuditEvents()[0].correlationId, 'corr-show-2');
});

await test('invoices:show — a generated correlation id echoes on success and errors', async () => {
  const okDeps = showFixtureDeps();
  const ok = await invoiceShowAs(SUB_CASHIER, INV_A1, {}, okDeps);
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  assert.equal(okDeps.getAuditEvents()[0].correlationId, okId);
  const err = await invoiceShowAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, showFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

await test('invoices:show — internal tenant/charge ids never leak into the data payload', async () => {
  const body = await bodyJson(await invoiceShowAs(SUB_CASHIER, INV_A1, {}, showFixtureDeps()));
  // The envelope meta legitimately echoes the authoritative tenant/facility
  // (context block); the DATA payload must expose only the approved fields.
  const raw = JSON.stringify(body.data);
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('chargeId'), false);
  assert.equal(raw.includes('lineNo'), false);
  assert.equal(raw.includes('token'), false);
  assert.equal(raw.includes('hash'), false);
  assert.equal(raw.includes('lockVersion') === false, false); // lockVersion IS part of the contract
});

/* PHASE 18 — invoices:payments (payment list for one invoice)         */
/* ================================================================== */

const PAY_A1 = 'aaaaaaaa-0000-4000-8000-000000000e01';
const PAY_A2 = 'aaaaaaaa-0000-4000-8000-000000000e02';
const ALLOC_A1 = 'aaaaaaaa-0000-4000-8000-000000000f01';
const ALLOC_A2 = 'aaaaaaaa-0000-4000-8000-000000000f02';
const ALLOC_A3 = 'aaaaaaaa-0000-4000-8000-000000000f03';

function paymentsReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/invoices-payments/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function paymentsAs(sub, id, headers = {}, deps = makeDeps()) {
  return handleInvoicesPayments(
    paymentsReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// A paid payment + allocation pair (org-a / fac-a1), shaped exactly like a
// Phase 16 capturePayment-created row.
function paidPair() {
  return {
    payments: [{
      id: PAY_A1, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      method: 'cash', providerRef: 'ref-cash-0001', amountMinor: 54350,
      currency: 'NPR', status: 'captured', idempotencyKey: 'pay-key-0001',
      receivedBy: 'u-cashier', receivedAt: '2026-03-02T12:00:00Z',
    }],
    allocations: [{
      id: ALLOC_A1, tenantId: 'org-a', paymentId: PAY_A1, invoiceId: INV_A1,
      amountMinor: 54350, allocatedAt: '2026-03-02T12:00:00Z', createdBy: 'u-cashier',
    }],
  };
}

// A fully eligible fixture set: an issued invoice (org-a / fac-a1) with a
// paid pair by default (BillingController::payments parity).
function paymentsFixtureDeps({ invoiceOverrides = {}, payments, allocations } = {}) {
  const p = paidPair();
  return makeDeps({}, {
    seedInvoices: [fullInvoice(INV_A1, invoiceOverrides)],
    seedPayments: payments === undefined ? p.payments : payments,
    seedAllocations: allocations === undefined ? p.allocations : allocations,
  });
}

// The exact BillingController::payments entry key set — nothing else ever
// leaves the handler.
const PAYMENT_ENTRY_KEYS = ['amountMinor', 'allocatedAt', 'method', 'paymentId'];

await test('invoices:payments — a cashier reads the in-scope invoice\'s payments (200, exact shape)', async () => {
  const deps = paymentsFixtureDeps();
  const response = await paymentsAs(SUB_CASHIER, INV_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare allocation list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 1);
  assert.deepEqual(Object.keys(body.data[0]).sort(), PAYMENT_ENTRY_KEYS.slice().sort());
  assert.equal(body.data[0].paymentId, PAY_A1);
  assert.equal(body.data[0].method, 'cash');
  assert.equal(body.data[0].amountMinor, 54350);
  assert.equal(body.data[0].allocatedAt, '2026-03-02T12:00:00Z');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('invoices:payments — allocations are ordered by allocated_at ascending', async () => {
  // Seeded out of order in the store — the read must order by allocated_at
  // exactly as `->orderBy('allocated_at')`.
  const pair = paidPair();
  const deps = paymentsFixtureDeps({
    payments: [pair.payments[0]],
    allocations: [
      { ...pair.allocations[0], id: ALLOC_A2, amountMinor: 54350, allocatedAt: '2026-03-02T11:00:00Z' },
      { ...pair.allocations[0], id: ALLOC_A1, amountMinor: 1, allocatedAt: '2026-03-02T12:00:00Z' },
    ],
  });
  const body = await bodyJson(await paymentsAs(SUB_CASHIER, INV_A1, {}, deps));
  assert.equal(body.data.length, 2);
  assert.equal(body.data[0].paymentId, PAY_A1);
  assert.equal(body.data[0].allocatedAt, '2026-03-02T11:00:00Z');
  assert.equal(body.data[0].amountMinor, 54350);
  assert.equal(body.data[1].allocatedAt, '2026-03-02T12:00:00Z');
  assert.equal(body.data[1].amountMinor, 1);
});

await test('invoices:payments — a payment outside the caller\'s facility scope renders method null (RLS parity)', async () => {
  // payment_allocations is TENANT_ONLY; payments is TENANT_FACILITY — an
  // allocation whose payment lives in the same tenant but a different
  // facility is visible to the caller while its payment is filtered out,
  // exactly like Laravel's `payment?->method`.
  const pair = paidPair();
  const deps = paymentsFixtureDeps({
    payments: [{ ...pair.payments[0], id: PAY_A2, facilityId: 'fac-a2' }],
    allocations: [{ ...pair.allocations[0], id: ALLOC_A3, paymentId: PAY_A2 }],
  });
  const body = await bodyJson(await paymentsAs(SUB_CASHIER, INV_A1, {}, deps));
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].paymentId, PAY_A2);
  assert.equal(body.data[0].method, null);
  assert.equal(body.data[0].amountMinor, 54350);
  assert.equal(body.data[0].allocatedAt, '2026-03-02T12:00:00Z');
});

await test('invoices:payments — an invoice with no payments returns an empty list', async () => {
  const deps = paymentsFixtureDeps({ payments: [], allocations: [] });
  const body = await bodyJson(await paymentsAs(SUB_CASHIER, INV_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('invoices:payments — the read mutates nothing and audits nothing (Laravel parity)', async () => {
  const deps = paymentsFixtureDeps();
  await paymentsAs(SUB_CASHIER, INV_A1, {}, deps);
  // No mutation: invoice + payment + allocation rows untouched.
  const stored = deps.getInvoices()[0];
  assert.equal(stored.status, 'issued');
  assert.equal(stored.paidMinor, 0);
  assert.equal(stored.lockVersion, 0);
  assert.equal(deps.getPayments().length, 1);
  assert.equal(deps.getAllocations().length, 1);
  // NO audit — BillingController::payments records no audit event (unlike
  // showInvoice's invoice.viewed). Adding one would invent behavior.
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('invoices:payments — internal payment fields never leak into the data payload', async () => {
  const body = await bodyJson(await paymentsAs(SUB_CASHIER, INV_A1, {}, paymentsFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // Only the four approved allocation fields; provider_ref / received_at are
  // loaded by Laravel but never presented.
  assert.equal(raw.includes('providerRef'), false);
  assert.equal(raw.includes('receivedAt'), false);
  assert.equal(raw.includes('receivedBy'), false);
  assert.equal(raw.includes('status'), false);
  assert.equal(raw.includes('idempotencyKey'), false);
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('facilityId'), false);
});

await test('invoices:payments — missing Authorization is rejected (401)', async () => {
  const response = await handleInvoicesPayments(paymentsReq(INV_A1), paymentsFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('invoices:payments — an invalid JWT is rejected (401)', async () => {
  const response = await handleInvoicesPayments(
    paymentsReq(INV_A1, { Authorization: 'Bearer not-a-jwt' }),
    paymentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('invoices:payments — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleInvoicesPayments(
    paymentsReq(INV_A1, { Authorization: `Bearer ${token}` }),
    paymentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('invoices:payments — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleInvoicesPayments(
    paymentsReq(INV_A1, { Authorization: `Bearer ${token}` }),
    paymentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('invoices:payments — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await paymentsAs(SUB_LOCKED, INV_A1, {}, paymentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:payments — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await paymentsAs(SUB_DISABLED, INV_A1, {}, paymentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:payments — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await paymentsAs(SUB_CASHIER, INV_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('invoices:payments — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await paymentsAs(SUB_NO_ASSIGNMENT, INV_A1, {}, paymentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('invoices:payments — a principal without billing:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = paymentsFixtureDeps();
  const response = await paymentsAs(SUB_RECEPTIONIST, INV_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getAllocations().length, 1);
});

await test('invoices:payments — a malformed invoice id is indistinguishable from a missing resource (404)', async () => {
  const response = await paymentsAs(SUB_CASHIER, 'not-a-uuid', {}, paymentsFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('invoices:payments — a nonexistent invoice returns 404 (existence never leaked)', async () => {
  const response = await paymentsAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, paymentsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('invoices:payments — a cross-tenant invoice is invisible (404)', async () => {
  const deps = makeDeps({}, { seedInvoices: [fullInvoice(INV_B1, { tenantId: 'org-b', facilityId: 'fac-b' })], seedPayments: [], seedAllocations: [] });
  const response = await paymentsAs(SUB_CASHIER, INV_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:payments — a cross-facility invoice is invisible (404)', async () => {
  const deps = makeDeps({}, { seedInvoices: [fullInvoice(INV_A2_OTHER_FAC, { facilityId: 'fac-a2' })], seedPayments: [], seedAllocations: [] });
  const response = await paymentsAs(SUB_CASHIER, INV_A2_OTHER_FAC, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('invoices:payments — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleInvoicesPayments(
    paymentsReq(INV_A1, { Authorization: `Bearer ${token}` }),
    paymentsFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].paymentId, PAY_A1);
});

await test('invoices:payments — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await paymentsAs(SUB_CASHIER, INV_A1, { 'X-Swasthya-Facility': 'fac-b' }, paymentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('invoices:payments — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await paymentsAs(SUB_CASHIER, INV_A1, { 'X-Swasthya-Branch': 'br-b' }, paymentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('invoices:payments — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = paymentsFixtureDeps();
  const response = await paymentsAs(SUB_CASHIER, INV_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 1);
});

await test('invoices:payments — correlation id propagates to the response', async () => {
  const response = await paymentsAs(SUB_CASHIER, INV_A1, { 'X-Correlation-Id': 'corr-pay-list-1' }, paymentsFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-pay-list-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-pay-list-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('invoices:payments — a generated correlation id echoes on success and errors', async () => {
  const ok = await paymentsAs(SUB_CASHIER, INV_A1, {}, paymentsFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await paymentsAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, paymentsFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* PHASE 19 — encounters:charges (posted charges of one encounter)    */
/* ================================================================== */

const CHG_A1 = 'aaaaaaaa-0000-4000-8000-0000000009a1';
const CHG_A2 = 'aaaaaaaa-0000-4000-8000-0000000009a2';
const CHG_A3 = 'aaaaaaaa-0000-4000-8000-0000000009a3';

function chargesReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/encounters-charges/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function chargesAs(sub, id, headers = {}, deps = makeDeps()) {
  return handleEncountersCharges(
    chargesReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// Three posted charges on ENC_A1 (org-a / fac-a1): an encounter charge, a
// prescription charge, and a VOIDED manual charge — all statuses return
// (the Laravel hasMany has no status filter). Seeded out of charged_at
// order in the store — the read must order by charged_at ascending.
function threeCharges() {
  return [
    { id: CHG_A2, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1, encounterId: ENC_A1, prescriptionId: RX_A1,
      sourceType: 'prescription', description: 'Paracetamol 500mg x 2', amountMinor: 500, currency: 'NPR',
      taxRateBps: 0, status: 'posted', chargedAt: '2026-03-02T10:05:00Z' },
    { id: CHG_A1, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1, encounterId: ENC_A1, prescriptionId: null,
      sourceType: 'encounter', description: 'General OPD — consultation', amountMinor: 50000, currency: 'NPR',
      taxRateBps: 0, status: 'posted', chargedAt: '2026-03-02T10:00:00Z' },
    { id: CHG_A3, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1, encounterId: ENC_A1, prescriptionId: null,
      sourceType: 'manual', description: 'Late fee adjustment', amountMinor: 4350, currency: 'NPR',
      taxRateBps: 0, status: 'voided', chargedAt: '2026-03-02T10:10:00Z' },
  ];
}

function chargesFixtureDeps({ encounterOverrides = {}, charges } = {}) {
  return makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1, encounterOverrides)],
    seedCharges: charges === undefined ? threeCharges() : charges,
  });
}

// The exact EncounterController::charges entry key set — nothing else ever
// leaves the handler.
const CHARGE_ENTRY_KEYS = ['amountMinor', 'chargedAt', 'currency', 'description', 'id', 'sourceType', 'status'];

await test('encounters:charges — a cashier reads the in-scope encounter\'s charges (200, exact shape)', async () => {
  const deps = chargesFixtureDeps();
  const response = await chargesAs(SUB_CASHIER, ENC_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare charge list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 3);
  assert.deepEqual(Object.keys(body.data[0]).sort(), CHARGE_ENTRY_KEYS.slice().sort());
  assert.equal(body.data[0].id, CHG_A1);
  assert.equal(body.data[0].sourceType, 'encounter');
  assert.equal(body.data[0].description, 'General OPD — consultation');
  assert.equal(body.data[0].amountMinor, 50000);
  assert.equal(body.data[0].currency, 'NPR');
  assert.equal(body.data[0].status, 'posted');
  assert.equal(body.data[0].chargedAt, '2026-03-02T10:00:00Z');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('encounters:charges — charges are ordered by charged_at ascending (all statuses return)', async () => {
  const body = await bodyJson(await chargesAs(SUB_CASHIER, ENC_A1, {}, chargesFixtureDeps()));
  assert.equal(body.data.length, 3);
  // Seeded out of order — the read orders by charged_at (Laravel parity),
  // and the VOIDED charge is included with its status presented.
  assert.equal(body.data[0].id, CHG_A1);
  assert.equal(body.data[0].chargedAt, '2026-03-02T10:00:00Z');
  assert.equal(body.data[1].id, CHG_A2);
  assert.equal(body.data[1].sourceType, 'prescription');
  assert.equal(body.data[1].chargedAt, '2026-03-02T10:05:00Z');
  assert.equal(body.data[2].id, CHG_A3);
  assert.equal(body.data[2].status, 'voided');
  assert.equal(body.data[2].chargedAt, '2026-03-02T10:10:00Z');
});

await test('encounters:charges — a missing charged_at renders null (Laravel nullable parity)', async () => {
  const deps = chargesFixtureDeps({
    charges: [{
      id: CHG_A1, tenantId: 'org-a', facilityId: 'fac-a1', patientId: PAT_A1,
      encounterId: ENC_A1, prescriptionId: null, sourceType: 'encounter',
      description: 'General OPD — consultation', amountMinor: 50000, currency: 'NPR',
      taxRateBps: 0, status: 'posted',
    }],
  });
  const body = await bodyJson(await chargesAs(SUB_CASHIER, ENC_A1, {}, deps));
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].chargedAt, null);
});

await test('encounters:charges — an encounter with no charges returns an empty list', async () => {
  const deps = chargesFixtureDeps({ charges: [] });
  const body = await bodyJson(await chargesAs(SUB_CASHIER, ENC_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('encounters:charges — the read mutates nothing and audits nothing (Laravel parity)', async () => {
  const deps = chargesFixtureDeps();
  await chargesAs(SUB_CASHIER, ENC_A1, {}, deps);
  // No mutation: the encounter + all three charges are untouched.
  const stored = deps.getEncounters()[0];
  assert.equal(stored.status, 'open');
  assert.equal(stored.lockVersion, 0);
  assert.equal(deps.getCharges().length, 3);
  assert.equal(deps.getCharges()[0].status, 'posted');
  assert.equal(deps.getCharges()[2].status, 'voided');
  // NO audit — EncounterController::charges records no audit event.
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('encounters:charges — internal charge fields never leak into the data payload', async () => {
  const body = await bodyJson(await chargesAs(SUB_CASHIER, ENC_A1, {}, chargesFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // Only the seven approved charge fields; tenant/facility/patient ids,
  // prescription linkage, tax, and attribution never leave the store.
  assert.equal(raw.includes('prescriptionId'), false);
  assert.equal(raw.includes('encounterId'), false);
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('facilityId'), false);
  assert.equal(raw.includes('patientId'), false);
  assert.equal(raw.includes('taxRateBps'), false);
  assert.equal(raw.includes('createdBy'), false);
});

await test('encounters:charges — missing Authorization is rejected (401)', async () => {
  const response = await handleEncountersCharges(chargesReq(ENC_A1), chargesFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:charges — an invalid JWT is rejected (401)', async () => {
  const response = await handleEncountersCharges(
    chargesReq(ENC_A1, { Authorization: 'Bearer not-a-jwt' }),
    chargesFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:charges — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleEncountersCharges(
    chargesReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    chargesFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounters:charges — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleEncountersCharges(
    chargesReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    chargesFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('encounters:charges — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await chargesAs(SUB_LOCKED, ENC_A1, {}, chargesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:charges — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await chargesAs(SUB_DISABLED, ENC_A1, {}, chargesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:charges — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await chargesAs(SUB_CASHIER, ENC_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounters:charges — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await chargesAs(SUB_NO_ASSIGNMENT, ENC_A1, {}, chargesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:charges — a principal without billing:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = chargesFixtureDeps();
  const response = await chargesAs(SUB_RECEPTIONIST, ENC_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getCharges().length, 3);
});

await test('encounters:charges — a malformed encounter id is indistinguishable from a missing resource (404)', async () => {
  const response = await chargesAs(SUB_CASHIER, 'not-a-uuid', {}, chargesFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('encounters:charges — a nonexistent encounter returns 404 (existence never leaked)', async () => {
  const response = await chargesAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, chargesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('encounters:charges — a cross-tenant encounter is invisible (404)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b' })], seedCharges: [] });
  const response = await chargesAs(SUB_CASHIER, ENC_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:charges — a cross-facility encounter is invisible (404)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2' })], seedCharges: [] });
  const response = await chargesAs(SUB_CASHIER, ENC_A2_OTHER_FAC, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:charges — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncountersCharges(
    chargesReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    chargesFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
  assert.equal(body.data[0].id, CHG_A1);
});

await test('encounters:charges — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await chargesAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-b' }, chargesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounters:charges — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await chargesAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Branch': 'br-b' }, chargesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounters:charges — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = chargesFixtureDeps();
  const response = await chargesAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
});

await test('encounters:charges — correlation id propagates to the response', async () => {
  const response = await chargesAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-charges-1' }, chargesFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-charges-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-charges-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('encounters:charges — a generated correlation id echoes on success and errors', async () => {
  const ok = await chargesAs(SUB_CASHIER, ENC_A1, {}, chargesFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await chargesAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, chargesFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* PHASE 20 — encounters:show (single-encounter READ)                 */
/* ================================================================== */

function encounterShowReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/encounters-show/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function encounterShowAs(sub, id, headers = {}, deps = makeDeps()) {
  return handleEncountersShow(
    encounterShowReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

function encounterShowFixtureDeps({ encounterOverrides = {} } = {}) {
  return makeDeps({}, { seedEncounters: [fullEncounter(ENC_A1, encounterOverrides)] });
}

// The exact EncounterController::present key set — nothing else ever
// leaves the handler.
const ENCOUNTER_SHOW_KEYS = ['appointmentId', 'endedAt', 'facilityId', 'id', 'lockVersion', 'patientId', 'providerStaffId', 'signedAt', 'startedAt', 'status', 'type'];

await test('encounters:show — a cashier reads an in-scope open encounter (200, exact present() shape)', async () => {
  const deps = encounterShowFixtureDeps();
  const response = await encounterShowAs(SUB_CASHIER, ENC_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), ENCOUNTER_SHOW_KEYS.slice().sort());
  assert.equal(body.data.id, ENC_A1);
  assert.equal(body.data.facilityId, 'fac-a1');
  assert.equal(body.data.patientId, PAT_A1);
  assert.equal(body.data.appointmentId, APPT_A1);
  assert.equal(body.data.providerStaffId, STAFF_A1);
  assert.equal(body.data.type, 'opd');
  assert.equal(body.data.status, 'open');
  assert.equal(body.data.startedAt, '2026-03-02T09:05:01Z');
  assert.equal(body.data.endedAt, null);
  assert.equal(body.data.signedAt, null);
  assert.equal(body.data.lockVersion, 0);
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('encounters:show — a signed encounter passes through with its server-derived timestamps', async () => {
  const deps = encounterShowFixtureDeps({
    encounterOverrides: { status: 'signed', endedAt: '2026-03-02T10:00:00Z', signedAt: '2026-03-02T10:00:01Z', lockVersion: 1 },
  });
  const body = await bodyJson(await encounterShowAs(SUB_CASHIER, ENC_A1, {}, deps));
  assert.equal(body.data.status, 'signed');
  assert.equal(body.data.endedAt, '2026-03-02T10:00:00Z');
  assert.equal(body.data.signedAt, '2026-03-02T10:00:01Z');
  assert.equal(body.data.lockVersion, 1);
});

await test('encounters:show — nullable appointment/ended/signed fields render null (Laravel parity)', async () => {
  const deps = encounterShowFixtureDeps({
    encounterOverrides: { appointmentId: null, startedAt: '2026-03-02T09:05:01Z', endedAt: null, signedAt: null },
  });
  const body = await bodyJson(await encounterShowAs(SUB_CASHIER, ENC_A1, {}, deps));
  assert.equal(body.data.appointmentId, null);
  assert.equal(body.data.endedAt, null);
  assert.equal(body.data.signedAt, null);
  assert.ok(body.data.startedAt);
});

await test('encounters:show — the read mutates nothing and audits encounter.viewed exactly once with the exact payload', async () => {
  const deps = encounterShowFixtureDeps();
  await encounterShowAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-enc-show-1' }, deps);
  // No mutation.
  const stored = deps.getEncounters()[0];
  assert.equal(stored.status, 'open');
  assert.equal(stored.lockVersion, 0);
  assert.equal(deps.getNotes().length, 0);
  assert.equal(deps.getCharges().length, 0);
  // Audit exactly once — encounter.viewed with the exact Laravel payload.
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'encounter.viewed');
  assert.equal(events[0].resourceType, 'encounter');
  assert.equal(events[0].resourceId, ENC_A1);
  assert.equal(events[0].actorId, 'u-cashier');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-enc-show-1');
  // Exact Laravel payload: { patientId } — nothing else leaks.
  assert.deepEqual(events[0].payload, { patientId: PAT_A1 });
});

await test('encounters:show — a failed read records no audit event', async () => {
  const deps = encounterShowFixtureDeps();
  await encounterShowAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, deps);
  assert.equal(deps.getAuditEvents().length, 0);
  const deniedDeps = encounterShowFixtureDeps();
  await encounterShowAs(SUB_RECEPTIONIST, ENC_A1, {}, deniedDeps);
  assert.equal(deniedDeps.getAuditEvents().length, 0);
});

await test('encounters:show — internal fields never leak into the data payload', async () => {
  const body = await bodyJson(await encounterShowAs(SUB_CASHIER, ENC_A1, {}, encounterShowFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // The envelope meta legitimately echoes the authoritative tenant/facility
  // (context block); the DATA payload must expose only the approved fields.
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('signedBy'), false);
  assert.equal(raw.includes('createdBy'), false);
  assert.equal(raw.includes('updatedBy'), false);
  assert.equal(raw.includes('appointment') && raw.includes('Id') === false, false);
  assert.equal(raw.includes('token'), false);
  assert.equal(raw.includes('hash'), false);
});

await test('encounters:show — missing Authorization is rejected (401)', async () => {
  const response = await handleEncountersShow(encounterShowReq(ENC_A1), encounterShowFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:show — an invalid JWT is rejected (401)', async () => {
  const response = await handleEncountersShow(
    encounterShowReq(ENC_A1, { Authorization: 'Bearer not-a-jwt' }),
    encounterShowFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:show — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleEncountersShow(
    encounterShowReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    encounterShowFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounters:show — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleEncountersShow(
    encounterShowReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    encounterShowFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('encounters:show — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await encounterShowAs(SUB_LOCKED, ENC_A1, {}, encounterShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:show — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await encounterShowAs(SUB_DISABLED, ENC_A1, {}, encounterShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:show — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await encounterShowAs(SUB_CASHIER, ENC_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounters:show — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await encounterShowAs(SUB_NO_ASSIGNMENT, ENC_A1, {}, encounterShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:show — a principal without encounter:view is denied (403 SCOPE_DENIED) with zero audit', async () => {
  const deps = encounterShowFixtureDeps();
  const response = await encounterShowAs(SUB_RECEPTIONIST, ENC_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('encounters:show — a malformed encounter id is indistinguishable from a missing resource (404)', async () => {
  const response = await encounterShowAs(SUB_CASHIER, 'not-a-uuid', {}, encounterShowFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('encounters:show — a nonexistent encounter returns 404 (existence never leaked)', async () => {
  const response = await encounterShowAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, encounterShowFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('encounters:show — a cross-tenant encounter is invisible (404)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b' })] });
  const response = await encounterShowAs(SUB_CASHIER, ENC_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:show — a cross-facility encounter is invisible (404)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2' })] });
  const response = await encounterShowAs(SUB_CASHIER, ENC_A2_OTHER_FAC, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:show — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncountersShow(
    encounterShowReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    encounterShowFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.id, ENC_A1);
});

await test('encounters:show — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await encounterShowAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-b' }, encounterShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounters:show — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await encounterShowAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Branch': 'br-b' }, encounterShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounters:show — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = encounterShowFixtureDeps();
  const response = await encounterShowAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.id, ENC_A1);
});

await test('encounters:show — correlation id propagates to the response and the audit record', async () => {
  const deps = encounterShowFixtureDeps();
  const response = await encounterShowAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-enc-show-2' }, deps);
  assert.equal(response.headers.get('X-Request-Id'), 'corr-enc-show-2');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-enc-show-2');
  assert.equal((await bodyJson(response)).error === undefined, true);
  assert.equal(deps.getAuditEvents()[0].correlationId, 'corr-enc-show-2');
});

await test('encounters:show — a generated correlation id echoes on success and errors', async () => {
  const okDeps = encounterShowFixtureDeps();
  const ok = await encounterShowAs(SUB_CASHIER, ENC_A1, {}, okDeps);
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  assert.equal(okDeps.getAuditEvents()[0].correlationId, okId);
  const err = await encounterShowAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, encounterShowFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* ================================================================== */
/* PHASE 21 — appointments:show (single-appointment READ)             */
/* ================================================================== */

function appointmentShowReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/appointments-show/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function appointmentShowAs(sub, id, headers = {}, deps = makeDeps()) {
  return handleAppointmentsShow(
    appointmentShowReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

function appointmentShowFixtureDeps({ appointmentOverrides = {} } = {}) {
  return makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, appointmentOverrides)] });
}

await test('appointments:show — a receptionist reads an in-scope booked appointment (200, exact present() shape)', async () => {
  const deps = appointmentShowFixtureDeps();
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.deepEqual(Object.keys(body.data).sort(), APPOINTMENT_KEYS.slice().sort());
  assert.equal(body.data.id, APPT_A1);
  assert.equal(body.data.facilityId, 'fac-a1');
  assert.equal(body.data.patientId, PAT_A1);
  assert.deepEqual(body.data.patient, { id: PAT_A1, mrn: 'MRN-A1-001', fullName: 'Aarav Shrestha' });
  assert.equal(body.data.providerStaffId, STAFF_A1);
  assert.deepEqual(body.data.provider, { id: STAFF_A1, fullName: 'Dr. Kiran Adhikari' });
  assert.equal(body.data.serviceId, null);
  assert.equal(body.data.appointmentType, 'opd');
  assert.equal(body.data.startsAt, '2026-03-02T09:00:00Z');
  assert.equal(body.data.endsAt, '2026-03-02T09:30:00Z');
  assert.equal(body.data.status, 'booked');
  assert.equal(body.data.tokenNo, null);
  assert.equal(body.data.source, 'counter');
  assert.equal(body.data.cancelReason, null);
  assert.equal(body.data.lockVersion, 0);
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('appointments:show — a checked-in appointment passes through with its server-derived token and status', async () => {
  const deps = appointmentShowFixtureDeps({
    appointmentOverrides: { status: 'checked_in', tokenNo: 7, lockVersion: 1 },
  });
  const body = await bodyJson(await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, deps));
  assert.equal(body.data.status, 'checked_in');
  assert.equal(body.data.tokenNo, 7);
  assert.equal(body.data.lockVersion, 1);
});

await test('appointments:show — serviceId and cancelReason render when present (Laravel parity)', async () => {
  const deps = appointmentShowFixtureDeps({
    appointmentOverrides: { serviceId: SVC_A1, status: 'cancelled', cancelReason: 'Patient unavailable' },
  });
  const body = await bodyJson(await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, deps));
  assert.equal(body.data.serviceId, SVC_A1);
  assert.equal(body.data.status, 'cancelled');
  assert.equal(body.data.cancelReason, 'Patient unavailable');
});

await test('appointments:show — a related patient/provider outside the caller\'s scope render null (RLS parity, never a leak)', async () => {
  // The appointment is in scope (fac-a1), but its patient belongs to fac-a2:
  // the ref resolves under the same claims → null, exactly like the
  // established payment?->method parity convention.
  const deps = appointmentShowFixtureDeps({
    appointmentOverrides: { patientId: PAT_A2, providerStaffId: STAFF_A2 },
  });
  const body = await bodyJson(await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, deps));
  assert.equal(body.data.patientId, PAT_A2);
  assert.equal(body.data.patient, null);
  assert.equal(body.data.provider, null);
});

await test('appointments:show — the read mutates nothing and records NO audit event (AppointmentController::show parity)', async () => {
  const deps = appointmentShowFixtureDeps();
  await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Correlation-Id': 'corr-appt-show-1' }, deps);
  // No mutation.
  const stored = deps.getAppointments()[0];
  assert.equal(stored.status, 'booked');
  assert.equal(stored.lockVersion, 0);
  assert.equal(stored.tokenNo, null);
  // NO audit — the Laravel show contract records no event (unlike
  // encounters:show).
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('appointments:show — internal fields never leak into the data payload', async () => {
  const body = await bodyJson(await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, appointmentShowFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // The envelope meta legitimately echoes the authoritative tenant/facility
  // (context block); the DATA payload must expose only the approved fields.
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('checkedInAt'), false);
  assert.equal(raw.includes('checkedInBy'), false);
  assert.equal(raw.includes('createdBy'), false);
  assert.equal(raw.includes('updatedBy'), false);
  assert.equal(raw.includes('createdAt'), false);
  assert.equal(raw.includes('updatedAt'), false);
  assert.equal(raw.includes('hash'), false);
  assert.equal(raw.includes('email'), false);
});

await test('appointments:show — missing Authorization is rejected (401)', async () => {
  const response = await handleAppointmentsShow(appointmentShowReq(APPT_A1), appointmentShowFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:show — an invalid JWT is rejected (401)', async () => {
  const response = await handleAppointmentsShow(
    appointmentShowReq(APPT_A1, { Authorization: 'Bearer not-a-jwt' }),
    appointmentShowFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:show — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_RECEPTIONIST, exp: NOW - 3600 });
  const response = await handleAppointmentsShow(
    appointmentShowReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    appointmentShowFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('appointments:show — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleAppointmentsShow(
    appointmentShowReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    appointmentShowFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('appointments:show — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await appointmentShowAs(SUB_LOCKED, APPT_A1, {}, appointmentShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:show — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await appointmentShowAs(SUB_DISABLED, APPT_A1, {}, appointmentShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:show — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_RECEPTIONIST ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('appointments:show — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await appointmentShowAs(SUB_NO_ASSIGNMENT, APPT_A1, {}, appointmentShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:show — a principal without appointment:view is denied (403 SCOPE_DENIED) with zero audit', async () => {
  const deps = appointmentShowFixtureDeps();
  const response = await appointmentShowAs(SUB_NO_PERM, APPT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('appointments:show — a malformed appointment id is indistinguishable from a missing resource (404)', async () => {
  const response = await appointmentShowAs(SUB_RECEPTIONIST, 'not-a-uuid', {}, appointmentShowFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('appointments:show — a nonexistent appointment returns 404 (existence never leaked)', async () => {
  const response = await appointmentShowAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000', {}, appointmentShowFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('appointments:show — a cross-tenant appointment is invisible (404)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_B1, { tenantId: 'org-b', facilityId: 'fac-b' })] });
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:show — a cross-facility appointment is invisible (404)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A2, { facilityId: 'fac-a2' })] });
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('appointments:show — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_RECEPTIONIST,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleAppointmentsShow(
    appointmentShowReq(APPT_A1, { Authorization: `Bearer ${token}` }),
    appointmentShowFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.id, APPT_A1);
});

await test('appointments:show — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Swasthya-Facility': 'fac-b' }, appointmentShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('appointments:show — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Swasthya-Branch': 'br-b' }, appointmentShowFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('appointments:show — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = appointmentShowFixtureDeps();
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.id, APPT_A1);
});

await test('appointments:show — correlation id propagates to the response', async () => {
  const deps = appointmentShowFixtureDeps();
  const response = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, { 'X-Correlation-Id': 'corr-appt-show-2' }, deps);
  assert.equal(response.headers.get('X-Request-Id'), 'corr-appt-show-2');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-appt-show-2');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('appointments:show — a generated correlation id echoes on success and errors', async () => {
  const okDeps = appointmentShowFixtureDeps();
  const ok = await appointmentShowAs(SUB_RECEPTIONIST, APPT_A1, {}, okDeps);
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await appointmentShowAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000', {}, appointmentShowFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* ================================================================== */
/* PHASE 22 — appointments:index (claims-scoped appointment LIST)     */
/* ================================================================== */

function appointmentIndexReq(query = '', headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/appointments-index${query === '' ? '' : `?${query}`}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function appointmentIndexAs(sub, query = '', headers = {}, deps = makeDeps()) {
  return handleAppointmentsIndex(
    appointmentIndexReq(query, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const APPT_A1_DAY2 = 'aaaaaaaa-0000-4000-8000-000000000a05';

// fac-a1 fixture: three appointments across two providers and two dates —
// startsAt is the ONLY ordering key (AppointmentController::index).
function appointmentIndexFixtureDeps({ seed } = {}) {
  return makeDeps({}, {
    seedAppointments: seed ?? [
      fullAppointment(APPT_A1, { startsAt: '2026-03-02T09:00:00Z', endsAt: '2026-03-02T09:30:00Z' }),
      fullAppointment(APPT_A2, {
        startsAt: '2026-03-02T11:00:00Z', endsAt: '2026-03-02T11:30:00Z', providerStaffId: STAFF_A1_EXCEPTION,
      }),
      fullAppointment(APPT_A1_DAY2, { startsAt: '2026-03-03T09:00:00Z', endsAt: '2026-03-03T09:30:00Z' }),
    ],
  });
}

await test('appointments:index — a receptionist lists the in-scope appointments ordered by startsAt (exact present() items)', async () => {
  const deps = appointmentIndexFixtureDeps();
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // Bare array, ordered by startsAt ascending — NO pagination (Laravel get()).
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1, APPT_A2, APPT_A1_DAY2]);
  // Exact present() item shape.
  assert.deepEqual(Object.keys(body.data[0]).sort(), APPOINTMENT_KEYS.slice().sort());
  const first = body.data[0];
  assert.equal(first.id, APPT_A1);
  assert.equal(first.facilityId, 'fac-a1');
  assert.equal(first.patientId, PAT_A1);
  assert.deepEqual(first.patient, { id: PAT_A1, mrn: 'MRN-A1-001', fullName: 'Aarav Shrestha' });
  assert.equal(first.providerStaffId, STAFF_A1);
  assert.deepEqual(first.provider, { id: STAFF_A1, fullName: 'Dr. Kiran Adhikari' });
  assert.equal(first.status, 'booked');
  assert.equal(first.startsAt, '2026-03-02T09:00:00Z');
  assert.equal(first.lockVersion, 0);
  // The second item carries its own provider ref (STAFF_A1_EXCEPTION).
  assert.deepEqual(body.data[1].provider, { id: STAFF_A1_EXCEPTION, fullName: 'Dr. Bipin Joshi' });
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('appointments:index — the date filter returns only same-day appointments (whereDate parity)', async () => {
  const deps = appointmentIndexFixtureDeps();
  const dayOne = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, 'date=2026-03-02', {}, deps));
  assert.deepEqual(dayOne.data.map((item) => item.id), [APPT_A1, APPT_A2]);
  const dayTwo = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, 'date=2026-03-03', {}, deps));
  assert.deepEqual(dayTwo.data.map((item) => item.id), [APPT_A1_DAY2]);
});

await test('appointments:index — the providerStaffId filter returns only that provider\'s appointments', async () => {
  const deps = appointmentIndexFixtureDeps();
  const forA1 = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, `providerStaffId=${STAFF_A1}`, {}, deps));
  assert.deepEqual(forA1.data.map((item) => item.id), [APPT_A1, APPT_A1_DAY2]);
  const forOther = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, `providerStaffId=${STAFF_A1_EXCEPTION}`, {}, deps));
  assert.deepEqual(forOther.data.map((item) => item.id), [APPT_A2]);
});

await test('appointments:index — combined date + providerStaffId filters intersect', async () => {
  const deps = appointmentIndexFixtureDeps();
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, `date=2026-03-02&providerStaffId=${STAFF_A1}`, {}, deps));
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1]);
  const none = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, `date=2026-03-03&providerStaffId=${STAFF_A1_EXCEPTION}`, {}, deps));
  assert.deepEqual(none.data, []);
});

await test('appointments:index — an unfiltered empty result returns a bare empty array', async () => {
  const deps = appointmentIndexFixtureDeps({ seed: [] });
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps));
  assert.deepEqual(body.data, []);
  assert.equal(body.meta.claimsIssued, true);
});

await test('appointments:index — unknown query parameters are ignored (Laravel parity: no validation exists)', async () => {
  const deps = appointmentIndexFixtureDeps();
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, 'foo=bar&baz=1&page=2', {}, deps));
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1, APPT_A2, APPT_A1_DAY2]);
});

await test('appointments:index — a malformed date filter fails closed with 500 (Laravel PG-cast parity)', async () => {
  const deps = appointmentIndexFixtureDeps();
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, 'date=not-a-date', {}, deps);
  assert.equal(response.status, 500);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SERVER_ERROR');
  assert.equal(body.error.message, 'An unexpected error occurred.');
  const badCalendar = await appointmentIndexAs(SUB_RECEPTIONIST, 'date=2026-13-99', {}, deps);
  assert.equal(badCalendar.status, 500);
  // Present-but-empty also reaches PG in Laravel → 500.
  const empty = await appointmentIndexAs(SUB_RECEPTIONIST, 'date=', {}, deps);
  assert.equal(empty.status, 500);
});

await test('appointments:index — a malformed providerStaffId filter fails closed with 500 (Laravel PG-cast parity)', async () => {
  const deps = appointmentIndexFixtureDeps();
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, 'providerStaffId=nope', {}, deps);
  assert.equal(response.status, 500);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SERVER_ERROR');
  const empty = await appointmentIndexAs(SUB_RECEPTIONIST, 'providerStaffId=', {}, deps);
  assert.equal(empty.status, 500);
});

await test('appointments:index — an org-level context sees every facility of the tenant (RLS facilityClause parity)', async () => {
  const orgView = { id: 'u-org-view', email: 'ov@a.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_RECEPTIONIST ? orgView : null),
    loadActiveAssignments: (userId) => userId === 'u-org-view'
      ? [{
          id: 'as-ov', userId: 'u-org-view', roleId: 'r-org-admin', tenantId: 'org-a', facilityId: null,
          branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: [{ code: 'appointment:view', scope: 'tenant' }] },
        }]
      : [],
  }, {
    seedAppointments: [
      fullAppointment(APPT_A1, { startsAt: '2026-03-02T09:00:00Z', endsAt: '2026-03-02T09:30:00Z' }),
      fullAppointment(APPT_A2, {
        startsAt: '2026-03-02T11:00:00Z', endsAt: '2026-03-02T11:30:00Z', facilityId: 'fac-a2', providerStaffId: STAFF_A2,
      }),
      fullAppointment(APPT_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 }),
    ],
  });
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  // fac-a1 + fac-a2 both visible; the org-b row stays invisible.
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1, APPT_A2]);
  assert.equal(body.meta.context.facilityId, null);
  // The fac-a2 appointment's refs stay visible under the org-level claim.
  assert.deepEqual(body.data[1].provider, { id: STAFF_A2, fullName: 'Dr. Maya Rai' });
});

await test('appointments:index — an out-of-facility patient ref renders null (RLS parity, never a leak)', async () => {
  const deps = makeDeps({}, { seedAppointments: [fullAppointment(APPT_A1, { patientId: PAT_A2 })] });
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps));
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].patientId, PAT_A2);
  assert.equal(body.data[0].patient, null);
  assert.deepEqual(body.data[0].provider, { id: STAFF_A1, fullName: 'Dr. Kiran Adhikari' });
});

await test('appointments:index — the read mutates nothing and records NO audit event (AppointmentController::index parity)', async () => {
  const deps = appointmentIndexFixtureDeps();
  await appointmentIndexAs(SUB_RECEPTIONIST, '', { 'X-Correlation-Id': 'corr-appt-index-1' }, deps);
  const stored = deps.getAppointments().map((a) => a.id).sort();
  assert.deepEqual(stored, [APPT_A1, APPT_A1_DAY2, APPT_A2].sort());
  assert.equal(deps.getAppointments().every((a) => a.lockVersion === 0), true);
  // NO audit — the Laravel index contract records no event.
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('appointments:index — internal fields never leak into the data payload', async () => {
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, appointmentIndexFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // The envelope meta legitimately echoes the authoritative tenant/facility
  // (context block); the DATA payload must expose only the approved fields.
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('checkedInAt'), false);
  assert.equal(raw.includes('checkedInBy'), false);
  assert.equal(raw.includes('createdBy'), false);
  assert.equal(raw.includes('updatedBy'), false);
  assert.equal(raw.includes('createdAt'), false);
  assert.equal(raw.includes('updatedAt'), false);
  assert.equal(raw.includes('hash'), false);
  assert.equal(raw.includes('email'), false);
});

await test('appointments:index — missing Authorization is rejected (401)', async () => {
  const response = await handleAppointmentsIndex(appointmentIndexReq(''), appointmentIndexFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:index — an invalid JWT is rejected (401)', async () => {
  const response = await handleAppointmentsIndex(
    appointmentIndexReq('', { Authorization: 'Bearer not-a-jwt' }),
    appointmentIndexFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:index — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_RECEPTIONIST, exp: NOW - 3600 });
  const response = await handleAppointmentsIndex(
    appointmentIndexReq('', { Authorization: `Bearer ${token}` }),
    appointmentIndexFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('appointments:index — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleAppointmentsIndex(
    appointmentIndexReq('', { Authorization: `Bearer ${token}` }),
    appointmentIndexFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('appointments:index — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await appointmentIndexAs(SUB_LOCKED, '', {}, appointmentIndexFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:index — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await appointmentIndexAs(SUB_DISABLED, '', {}, appointmentIndexFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:index — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_RECEPTIONIST ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('appointments:index — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await appointmentIndexAs(SUB_NO_ASSIGNMENT, '', {}, appointmentIndexFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:index — a principal without appointment:view is denied (403 SCOPE_DENIED)', async () => {
  const response = await appointmentIndexAs(SUB_NO_PERM, '', {}, appointmentIndexFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('appointments:index — a cross-tenant appointment never appears in the list', async () => {
  const deps = appointmentIndexFixtureDeps({
    seed: [
      fullAppointment(APPT_A1),
      fullAppointment(APPT_B1, { tenantId: 'org-b', facilityId: 'fac-b', providerStaffId: STAFF_B1 }),
    ],
  });
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps));
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1]);
});

await test('appointments:index — a cross-facility appointment never appears in the list', async () => {
  const deps = appointmentIndexFixtureDeps({
    seed: [
      fullAppointment(APPT_A1),
      fullAppointment(APPT_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 }),
    ],
  });
  const body = await bodyJson(await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, deps));
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1]);
});

await test('appointments:index — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_RECEPTIONIST,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleAppointmentsIndex(
    appointmentIndexReq('', { Authorization: `Bearer ${token}` }),
    appointmentIndexFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1, APPT_A2, APPT_A1_DAY2]);
});

await test('appointments:index — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', { 'X-Swasthya-Facility': 'fac-b' }, appointmentIndexFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('appointments:index — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', { 'X-Swasthya-Branch': 'br-b' }, appointmentIndexFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('appointments:index — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = appointmentIndexFixtureDeps();
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.deepEqual(body.data.map((item) => item.id), [APPT_A1, APPT_A2, APPT_A1_DAY2]);
});

await test('appointments:index — correlation id propagates to the response', async () => {
  const deps = appointmentIndexFixtureDeps();
  const response = await appointmentIndexAs(SUB_RECEPTIONIST, '', { 'X-Correlation-Id': 'corr-appt-index-2' }, deps);
  assert.equal(response.headers.get('X-Request-Id'), 'corr-appt-index-2');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-appt-index-2');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('appointments:index — a generated correlation id echoes on success and errors', async () => {
  const okDeps = appointmentIndexFixtureDeps();
  const ok = await appointmentIndexAs(SUB_RECEPTIONIST, '', {}, okDeps);
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await appointmentIndexAs(SUB_RECEPTIONIST, 'date=not-a-date', {}, appointmentIndexFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* ================================================================== */
/* PHASE 23 — patients:search (candidate patient SEARCH)              */
/* ================================================================== */

const PAT_A1_B = 'aaaaaaaa-0000-4000-8000-000000000102'; // Bimala Gurung (fac-a1)
const PAT_A1_C = 'aaaaaaaa-0000-4000-8000-000000000103'; // Empty Fields (fac-a1)

// pg_trgm parity: trigram similarity (lowercased inputs; two-space padding;
// |A ∩ B| / |A ∪ B|; empty → 0). The REAL pg_trgm scores are proven at the
// DB tier — this is the deterministic harness simulation.
function trigramsOf(value) {
  const padded = `  ${value}  `;
  const set = new Set();
  for (let i = 0; i + 3 <= padded.length; i += 1) set.add(padded.slice(i, i + 3));
  return set;
}
function trigramSimilarity(a, b) {
  const ta = trigramsOf(a);
  const tb = trigramsOf(b);
  if (ta.size === 0 || tb.size === 0) return 0;
  let shared = 0;
  for (const t of ta) if (tb.has(t)) shared += 1;
  return shared / (ta.size + tb.size - shared);
}
// SQL LIKE parity (wildcards % / _ unescaped — exactly like the deployed
// SQL and Laravel's like bindings).
function sqlLike(value, pattern) {
  const escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const regex = new RegExp(`^${escaped.replace(/%/g, '.*').replace(/_/g, '.')}$`);
  return regex.test(value);
}

const SEARCH_RESULT_KEYS = ['dateOfBirth', 'facilityId', 'fullName', 'id', 'mrn', 'score', 'sex'];

function patientSearchReq(query, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-search?${query}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function patientSearchAs(sub, query, headers = {}, deps = makeDeps()) {
  return handlePatientsSearch(
    patientSearchReq(query, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('patients:search — a cashier finds an active in-scope patient by name substring (exact 7-field item, hint, audit)', async () => {
  const deps = makeDeps();
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar', { 'X-Correlation-Id': 'corr-search-1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  assert.equal(body.data.length, 1);
  assert.deepEqual(Object.keys(body.data[0]).sort(), SEARCH_RESULT_KEYS.slice().sort());
  assert.equal(body.data[0].id, PAT_A1);
  assert.equal(body.data[0].mrn, 'MRN-A1-001');
  assert.equal(body.data[0].fullName, 'Aarav Shrestha');
  assert.equal(body.data[0].dateOfBirth, '1990-01-01');
  assert.equal(body.data[0].sex, 'male');
  assert.equal(body.data[0].facilityId, 'fac-a1');
  assert.ok(body.data[0].score > 0);
  // The exact Laravel hint strings.
  assert.equal(body.meta.search.hint, '1 candidate(s) found — confirm identity before opening.');
  assert.equal(body.meta.claimsIssued, true);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  // Audit exactly once — patient.searched with the exact payload.
  const events = deps.getAuditEvents();
  assert.equal(events.length, 1);
  assert.equal(events[0].action, 'patient.searched');
  assert.equal(events[0].resourceType, 'patient_search');
  assert.equal(events[0].resourceId, null);
  assert.equal(events[0].actorId, 'u-cashier');
  assert.equal(events[0].tenantId, 'org-a');
  assert.equal(events[0].facilityId, 'fac-a1');
  assert.equal(events[0].correlationId, 'corr-search-1');
  assert.deepEqual(events[0].payload, { resultCount: 1 });
});

await test('patients:search — the match is case-insensitive (lower() parity)', async () => {
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=AAR', {}, makeDeps()));
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].id, PAT_A1);
  const lower = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=aar', {}, makeDeps()));
  assert.deepEqual(lower.data, body.data);
});

await test('patients:search — the MRN prefix match returns every MRN-A1 patient (scores tie at 0)', async () => {
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=mrn-a1', {}, makeDeps()));
  assert.deepEqual(body.data.map((item) => item.id).sort(), [PAT_A1, PAT_A1_B, PAT_A1_C].sort());
  assert.equal(body.data.every((item) => item.score === 0), true);
  assert.equal(body.meta.search.hint, '3 candidate(s) found — confirm identity before opening.');
});

await test('patients:search — an empty result returns [] with the exact no-candidates hint and resultCount 0', async () => {
  const deps = makeDeps();
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=zzzz', {}, deps));
  assert.deepEqual(body.data, []);
  assert.equal(body.meta.search.hint, 'No candidates found.');
  assert.deepEqual(deps.getAuditEvents()[0].payload, { resultCount: 0 });
});

await test('patients:search — a cross-facility patient never appears (facility scope)', async () => {
  // Chandra Thapa is fac-a2 — invisible to the fac-a1 cashier.
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=chandra', {}, makeDeps()));
  assert.deepEqual(body.data, []);
});

await test('patients:search — a cross-tenant patient never appears (tenant scope)', async () => {
  // Devaki Lama is org-b — invisible to the org-a cashier.
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=devaki', {}, makeDeps()));
  assert.deepEqual(body.data, []);
});

await test('patients:search — SQL LIKE wildcards are honored, unescaped (Laravel parity)', async () => {
  // 'a%r' matches any full name with 'a' … then 'r' — Aarav Shrestha and
  // Bimala Gurung (both fac-a1).
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=a%25r', {}, makeDeps()));
  assert.deepEqual(body.data.map((item) => item.id).sort(), [PAT_A1, PAT_A1_B].sort());
});

await test('patients:search — whitespace-only q passes validation (raw min:2) and searches the trimmed empty term', async () => {
  // '    ' (4 spaces) passes min:2 on the RAW value, then trims to '' —
  // like '%%' matches every active in-scope patient (Laravel parity).
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=%20%20%20%20', {}, makeDeps()));
  assert.deepEqual(body.data.map((item) => item.id).sort(), [PAT_A1, PAT_A1_B, PAT_A1_C].sort());
  assert.equal(body.data.every((item) => item.score === 0), true);
});

await test('patients:search — an org-level context searches the whole tenant (facility null → no facility filter)', async () => {
  const orgView = { id: 'u-org-search', email: 'os@a.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? orgView : null),
    loadActiveAssignments: (userId) => userId === 'u-org-search'
      ? [{
          id: 'as-ps', userId: 'u-org-search', roleId: 'r-org-admin', tenantId: 'org-a', facilityId: null,
          branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: [{ code: 'patient:search', scope: 'tenant' }] },
        }]
      : [],
  });
  // Chandra Thapa is fac-a2 — visible under the org-level claim.
  const inFacA2 = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=chandra', {}, deps));
  assert.equal(inFacA2.data.length, 1);
  assert.equal(inFacA2.data[0].id, PAT_A2);
  // Devaki Lama is org-b — still invisible.
  const crossTenant = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=devaki', {}, deps));
  assert.deepEqual(crossTenant.data, []);
});

await test('patients:search — a missing q fails validation (422 REQUIRED)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, '', {}, makeDeps());
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'VALIDATION_ERROR');
  assert.deepEqual(body.error.details, [{ field: 'q', code: 'REQUIRED', message: 'The q field is required.' }]);
});

await test('patients:search — a too-short q fails validation (422 OUT_OF_RANGE)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, 'q=a', {}, makeDeps());
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.deepEqual(body.error.details, [{ field: 'q', code: 'OUT_OF_RANGE', message: 'The q field must be at least 2 characters.' }]);
});

await test('patients:search — an over-long q fails validation (422 OUT_OF_RANGE)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, `q=${'x'.repeat(256)}`, {}, makeDeps());
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.deepEqual(body.error.details, [{ field: 'q', code: 'OUT_OF_RANGE', message: 'The q field must not be greater than 255 characters.' }]);
});

await test('patients:search — an unknown query parameter is rejected (ApiRequest strict mode)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar&foo=1', {}, makeDeps());
  assert.equal(response.status, 422);
  const body = await bodyJson(response);
  assert.deepEqual(body.error.details, [{ field: 'foo', code: 'VALIDATION_ERROR', message: 'Field "foo" is not allowed.' }]);
  // Unknown parameter + missing q → both details.
  const both = await bodyJson(await patientSearchAs(SUB_CASHIER, 'foo=1', {}, makeDeps()));
  assert.equal(both.error.message, '2 field(s) failed validation.');
  assert.deepEqual(both.error.details.map((d) => d.field).sort(), ['foo', 'q']);
});

await test('patients:search — a failed search records NO audit event', async () => {
  const deps = makeDeps();
  await patientSearchAs(SUB_CASHIER, 'q=a', {}, deps);
  assert.equal(deps.getAuditEvents().length, 0);
  const denied = makeDeps();
  await patientSearchAs(SUB_RECEPTIONIST, 'q=aar', {}, denied);
  assert.equal(denied.getAuditEvents().length, 0);
});

await test('patients:search — missing Authorization is rejected (401)', async () => {
  const response = await handlePatientsSearch(patientSearchReq('q=aar'), makeDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:search — an invalid JWT is rejected (401)', async () => {
  const response = await handlePatientsSearch(
    patientSearchReq('q=aar', { Authorization: 'Bearer not-a-jwt' }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:search — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handlePatientsSearch(
    patientSearchReq('q=aar', { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:search — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsSearch(
    patientSearchReq('q=aar', { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:search — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await patientSearchAs(SUB_LOCKED, 'q=aar', {}, makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:search — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await patientSearchAs(SUB_DISABLED, 'q=aar', {}, makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:search — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar', {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:search — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await patientSearchAs(SUB_NO_ASSIGNMENT, 'q=aar', {}, makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:search — a principal with patient:view but NOT patient:search is denied (403 SCOPE_DENIED)', async () => {
  const deps = makeDeps();
  const response = await patientSearchAs(SUB_RECEPTIONIST, 'q=aar', {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('patients:search — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsSearch(
    patientSearchReq('q=aar', { Authorization: `Bearer ${token}` }),
    makeDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data[0].id, PAT_A1);
});

await test('patients:search — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar', { 'X-Swasthya-Facility': 'fac-b' }, makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:search — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar', { 'X-Swasthya-Branch': 'br-b' }, makeDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:search — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar', { 'X-Swasthya-Facility': 'fac-a1' }, makeDeps());
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data[0].id, PAT_A1);
});

await test('patients:search — internal fields never leak into the result items', async () => {
  const body = await bodyJson(await patientSearchAs(SUB_CASHIER, 'q=aar', {}, makeDeps()));
  const raw = JSON.stringify(body.data);
  // The envelope meta legitimately echoes the authoritative tenant/facility
  // (context block); the DATA items expose only the 7 approved fields.
  assert.equal(raw.includes('status'), false);
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('createdAt'), false);
  assert.equal(raw.includes('updatedAt'), false);
  assert.equal(raw.includes('bloodGroup'), false);
  assert.equal(raw.includes('email'), false);
  assert.equal(raw.includes('hash'), false);
});

await test('patients:search — correlation id propagates to the response and the audit record', async () => {
  const deps = makeDeps();
  const response = await patientSearchAs(SUB_CASHIER, 'q=aar', { 'X-Correlation-Id': 'corr-search-2' }, deps);
  assert.equal(response.headers.get('X-Request-Id'), 'corr-search-2');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-search-2');
  assert.equal((await bodyJson(response)).error === undefined, true);
  assert.equal(deps.getAuditEvents()[0].correlationId, 'corr-search-2');
});

await test('patients:search — a generated correlation id echoes on success and errors', async () => {
  const okDeps = makeDeps();
  const ok = await patientSearchAs(SUB_CASHIER, 'q=aar', {}, okDeps);
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  assert.equal(okDeps.getAuditEvents()[0].correlationId, okId);
  const err = await patientSearchAs(SUB_CASHIER, 'q=a', {}, makeDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* PHASE 25 — encounters:notes (the notes LIST of one encounter)       */
/* ================================================================== */

function notesListReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/encounters-notes/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function notesListAs(sub, id, headers = {}, deps = makeDeps()) {
  return handleEncountersNotes(
    notesListReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const NOTE_A2 = 'aaaaaaaa-0000-4000-8000-000000000e02';
const NOTE_B1 = 'aaaaaaaa-0000-4000-8000-000000000e03';

// Three notes on ENC_A1 (org-a / fac-a1): a draft, a signed, and an
// amended — ALL statuses return (the Laravel hasMany applies no status
// filter). Seeded out of created_at order in the store — the read must
// order by created_at ascending (the exact `->orderBy('created_at')`).
function threeNotes() {
  return [
    fullNote(NOTE_A1, ENC_A1, { content: { complaint: 'Fever since yesterday' }, createdAt: '2026-03-02T09:05:00Z' }),
    fullNote(NOTE_B1, ENC_A1, { content: { assessment: 'Viral illness' }, status: 'signed', signedAt: '2026-03-02T09:10:00Z', createdAt: '2026-03-02T09:06:00Z' }),
    fullNote(NOTE_A2, ENC_A1, { content: { plan: 'Rest, fluids' }, status: 'amended', signedAt: '2026-03-02T09:12:00Z', createdAt: '2026-03-02T09:08:00Z' }),
  ];
}

function notesFixtureDeps({ encounterOverrides = {}, notes } = {}) {
  return makeDeps({}, {
    seedEncounters: [fullEncounter(ENC_A1, encounterOverrides)],
    seedNotes: notes === undefined ? threeNotes() : notes,
  });
}

// The exact EncounterController::notes entry key set — nothing else ever
// leaves the handler.
const NOTE_LIST_KEYS = ['author', 'content', 'id', 'noteType', 'signedAt', 'status'];
const AUTHOR_REF_KEYS = ['fullName', 'id'];

await test('encounters:notes — a cashier reads the in-scope encounter\'s notes (200, exact shape, author ref)', async () => {
  const deps = notesFixtureDeps();
  const response = await notesListAs(SUB_CASHIER, ENC_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare note list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 3);
  assert.deepEqual(Object.keys(body.data[0]).sort(), NOTE_LIST_KEYS.slice().sort());
  assert.equal(body.data[0].id, NOTE_A1);
  assert.equal(body.data[0].noteType, 'consultation');
  assert.deepEqual(body.data[0].content, { complaint: 'Fever since yesterday' });
  assert.equal(body.data[0].status, 'draft');
  assert.equal(body.data[0].signedAt, null);
  // The author ref resolves under the same claims (staff TENANT_FACILITY).
  assert.deepEqual(Object.keys(body.data[0].author).sort(), AUTHOR_REF_KEYS.slice().sort());
  assert.equal(body.data[0].author.id, STAFF_A1);
  assert.equal(body.data[0].author.fullName, 'Dr. Kiran Adhikari');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('encounters:notes — notes are ordered by created_at ascending (all statuses return)', async () => {
  const body = await bodyJson(await notesListAs(SUB_CASHIER, ENC_A1, {}, notesFixtureDeps()));
  assert.equal(body.data.length, 3);
  // Seeded out of order — the read orders by created_at (Laravel parity),
  // and the draft AND signed AND amended notes all return.
  assert.equal(body.data[0].id, NOTE_A1);
  assert.equal(body.data[0].status, 'draft');
  assert.equal(body.data[1].id, NOTE_B1);
  assert.equal(body.data[1].status, 'signed');
  assert.equal(body.data[1].signedAt, '2026-03-02T09:10:00Z');
  assert.equal(body.data[2].id, NOTE_A2);
  assert.equal(body.data[2].status, 'amended');
  assert.equal(body.data[2].signedAt, '2026-03-02T09:12:00Z');
});

await test('encounters:notes — a note without a signed_at renders null (Laravel nullable parity)', async () => {
  const body = await bodyJson(await notesListAs(SUB_CASHIER, ENC_A1, {}, notesFixtureDeps({
    notes: [fullNote(NOTE_A1, ENC_A1)],
  })));
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].signedAt, null);
});

await test('encounters:notes — an out-of-scope author renders null (ref resolved under the claims)', async () => {
  const deps = notesFixtureDeps({
    notes: [fullNote(NOTE_A1, ENC_A1, { authorStaffId: STAFF_A2 })],
  });
  const body = await bodyJson(await notesListAs(SUB_CASHIER, ENC_A1, {}, deps));
  assert.equal(body.data.length, 1);
  // STAFF_A2 is org-a / fac-a2 — outside the fac-a1 claims → null ref,
  // never a cross-facility leak.
  assert.equal(body.data[0].author, null);
});

await test('encounters:notes — an encounter with no notes returns an empty list', async () => {
  const deps = notesFixtureDeps({ notes: [] });
  const body = await bodyJson(await notesListAs(SUB_CASHIER, ENC_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('encounters:notes — the read mutates nothing and audits nothing (Laravel parity)', async () => {
  const deps = notesFixtureDeps();
  await notesListAs(SUB_CASHIER, ENC_A1, {}, deps);
  // No mutation: the encounter + all three notes are untouched.
  assert.equal(deps.getEncounters()[0].status, 'open');
  assert.equal(deps.getEncounters()[0].lockVersion, 0);
  assert.equal(deps.getNotes().length, 3);
  assert.equal(deps.getNotes()[0].status, 'draft');
  assert.equal(deps.getNotes()[2].status, 'amended');
  // NO audit — EncounterController::notes records no audit event.
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('encounters:notes — internal note fields never leak into the data payload', async () => {
  const body = await bodyJson(await notesListAs(SUB_CASHIER, ENC_A1, {}, notesFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // Only the six approved note fields; the encounter/tenant/facility ids,
  // author linkage, lock version, and created_at never leave the store.
  assert.equal(raw.includes('encounterId'), false);
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('facilityId'), false);
  assert.equal(raw.includes('authorStaffId'), false);
  assert.equal(raw.includes('lockVersion'), false);
  assert.equal(raw.includes('createdAt'), false);
});

await test('encounters:notes — missing Authorization is rejected (401)', async () => {
  const response = await handleEncountersNotes(notesListReq(ENC_A1), notesFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:notes — an invalid JWT is rejected (401)', async () => {
  const response = await handleEncountersNotes(
    notesListReq(ENC_A1, { Authorization: 'Bearer not-a-jwt' }),
    notesFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('encounters:notes — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleEncountersNotes(
    notesListReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    notesFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('encounters:notes — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleEncountersNotes(
    notesListReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    notesFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('encounters:notes — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await notesListAs(SUB_LOCKED, ENC_A1, {}, notesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:notes — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await notesListAs(SUB_DISABLED, ENC_A1, {}, notesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:notes — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await notesListAs(SUB_CASHIER, ENC_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('encounters:notes — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await notesListAs(SUB_NO_ASSIGNMENT, ENC_A1, {}, notesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('encounters:notes — a principal without encounter:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = notesFixtureDeps();
  const response = await notesListAs(SUB_RECEPTIONIST, ENC_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getNotes().length, 3);
});

await test('encounters:notes — a malformed encounter id is indistinguishable from a missing resource (404)', async () => {
  const response = await notesListAs(SUB_CASHIER, 'not-a-uuid', {}, notesFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('encounters:notes — a nonexistent encounter returns 404 (existence never leaked)', async () => {
  const response = await notesListAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, notesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('encounters:notes — a cross-tenant encounter is invisible (404)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_B1, { tenantId: 'org-b', facilityId: 'fac-b' })], seedNotes: [] });
  const response = await notesListAs(SUB_CASHIER, ENC_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:notes — a cross-facility encounter is invisible (404)', async () => {
  const deps = makeDeps({}, { seedEncounters: [fullEncounter(ENC_A2_OTHER_FAC, { facilityId: 'fac-a2', providerStaffId: STAFF_A2 })], seedNotes: [] });
  const response = await notesListAs(SUB_CASHIER, ENC_A2_OTHER_FAC, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('encounters:notes — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleEncountersNotes(
    notesListReq(ENC_A1, { Authorization: `Bearer ${token}` }),
    notesFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
  assert.equal(body.data[0].id, NOTE_A1);
});

await test('encounters:notes — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await notesListAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-b' }, notesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('encounters:notes — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await notesListAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Branch': 'br-b' }, notesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('encounters:notes — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = notesFixtureDeps();
  const response = await notesListAs(SUB_CASHIER, ENC_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
});

await test('encounters:notes — correlation id propagates to the response', async () => {
  const response = await notesListAs(SUB_CASHIER, ENC_A1, { 'X-Correlation-Id': 'corr-notes-1' }, notesFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-notes-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-notes-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('encounters:notes — a generated correlation id echoes on success and errors', async () => {
  const ok = await notesListAs(SUB_CASHIER, ENC_A1, {}, notesFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await notesListAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, notesFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* PHASE 26 — patients:timeline (the patient-scoped timeline read)      */
/* ================================================================== */

function timelineReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-timeline/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function timelineAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsTimeline(
    timelineReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const TL_1 = 'aaaaaaaa-0000-4000-8000-000000000f01';
const TL_2 = 'aaaaaaaa-0000-4000-8000-000000000f02';
const TL_3 = 'aaaaaaaa-0000-4000-8000-000000000f03';

function fullTimelineEntry(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', patientId: PAT_A1,
    occurredAt: '2026-03-02T10:00:00Z', eventType: 'patient.registered',
    summary: { by: 'registration-desk' },
    ...overrides,
  };
}

// Three entries on PAT_A1 (org-a / fac-a1), seeded OUT of occurred_at
// order — the read must order by occurred_at DESC then id DESC (the exact
// Laravel `->orderByDesc('occurred_at')->orderByDesc('id')`), covering
// distinct event types (all schema-valid: occurred_at is NOT NULL).
function threeTimelineEntries() {
  return [
    fullTimelineEntry(TL_2, { occurredAt: '2026-03-02T09:00:00Z', eventType: 'patient.identifier.added', summary: { type: 'national_id' } }),
    fullTimelineEntry(TL_3, { occurredAt: '2026-03-02T08:00:00Z', eventType: 'patient.document.attached', summary: { kind: 'report' } }),
    fullTimelineEntry(TL_1, { occurredAt: '2026-03-02T10:00:00Z', eventType: 'patient.registered', summary: { by: 'registration-desk' } }),
  ];
}

function timelineFixtureDeps({ entries } = {}) {
  return makeDeps({}, { seedTimeline: entries === undefined ? threeTimelineEntries() : entries });
}

// The exact PatientController::timeline entry key set — nothing else ever
// leaves the handler.
const TIMELINE_ENTRY_KEYS = ['eventType', 'id', 'occurredAt', 'summary'];

await test('patients:timeline — a cashier reads the in-scope patient\'s timeline (200, exact shape)', async () => {
  const deps = timelineFixtureDeps();
  const response = await timelineAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare entry list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 3);
  assert.deepEqual(Object.keys(body.data[0]).sort(), TIMELINE_ENTRY_KEYS.slice().sort());
  assert.equal(body.data[0].id, TL_1);
  assert.equal(body.data[0].eventType, 'patient.registered');
  assert.deepEqual(body.data[0].summary, { by: 'registration-desk' });
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:timeline — entries are ordered by occurred_at DESC then id DESC (Laravel parity)', async () => {
  const body = await bodyJson(await timelineAs(SUB_CASHIER, PAT_A1, {}, timelineFixtureDeps()));
  assert.equal(body.data.length, 3);
  // Seeded out of order — the read orders by occurred_at DESC / id DESC.
  assert.equal(body.data[0].id, TL_1);
  assert.equal(body.data[0].occurredAt, '2026-03-02T10:00:00Z');
  assert.equal(body.data[1].id, TL_2);
  assert.equal(body.data[1].eventType, 'patient.identifier.added');
  assert.equal(body.data[1].occurredAt, '2026-03-02T09:00:00Z');
  assert.equal(body.data[2].id, TL_3);
  assert.equal(body.data[2].eventType, 'patient.document.attached');
  assert.equal(body.data[2].occurredAt, '2026-03-02T08:00:00Z');
});

await test('patients:timeline — a null occurred_at sorts FIRST (PostgreSQL DESC NULLS FIRST parity)', async () => {
  const deps = timelineFixtureDeps({
    entries: [
      fullTimelineEntry(TL_2, { occurredAt: '2026-03-02T09:00:00Z', eventType: 'patient.identifier.added' }),
      fullTimelineEntry(TL_3, { occurredAt: null, eventType: 'patient.document.attached' }),
    ],
  });
  const body = await bodyJson(await timelineAs(SUB_CASHIER, PAT_A1, {}, deps));
  assert.equal(body.data.length, 2);
  // `order by occurred_at desc` in PostgreSQL defaults to NULLS FIRST — the
  // null entry leads; the presenter renders its occurredAt as null.
  assert.equal(body.data[0].id, TL_3);
  assert.equal(body.data[0].occurredAt, null);
  assert.equal(body.data[1].id, TL_2);
  assert.equal(body.data[1].occurredAt, '2026-03-02T09:00:00Z');
});

await test('patients:timeline — a patient with an empty timeline returns an empty list', async () => {
  const deps = timelineFixtureDeps({ entries: [] });
  const body = await bodyJson(await timelineAs(SUB_CASHIER, PAT_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('patients:timeline — the read mutates nothing and audits nothing (Laravel parity)', async () => {
  const deps = timelineFixtureDeps();
  await timelineAs(SUB_CASHIER, PAT_A1, {}, deps);
  // No mutation: all three entries are untouched.
  assert.equal(deps.getTimeline().length, 3);
  assert.equal(deps.getTimeline()[0].eventType, 'patient.identifier.added');
  assert.equal(deps.getTimeline()[2].eventType, 'patient.registered');
  // NO audit — PatientController::timeline records no audit event.
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('patients:timeline — internal timeline fields never leak into the data payload', async () => {
  const body = await bodyJson(await timelineAs(SUB_CASHIER, PAT_A1, {}, timelineFixtureDeps()));
  const raw = JSON.stringify(body.data);
  // Only the four approved entry fields; the tenant/patient ids, actor,
  // correlation id, and created/updated timestamps never leave the store.
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('patientId'), false);
  assert.equal(raw.includes('actorId'), false);
  assert.equal(raw.includes('correlationId'), false);
  assert.equal(raw.includes('createdAt'), false);
  assert.equal(raw.includes('updatedAt'), false);
});

await test('patients:timeline — missing Authorization is rejected (401)', async () => {
  const response = await handlePatientsTimeline(timelineReq(PAT_A1), timelineFixtureDeps());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:timeline — an invalid JWT is rejected (401)', async () => {
  const response = await handlePatientsTimeline(
    timelineReq(PAT_A1, { Authorization: 'Bearer not-a-jwt' }),
    timelineFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:timeline — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handlePatientsTimeline(
    timelineReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    timelineFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:timeline — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsTimeline(
    timelineReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    timelineFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:timeline — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await timelineAs(SUB_LOCKED, PAT_A1, {}, timelineFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:timeline — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await timelineAs(SUB_DISABLED, PAT_A1, {}, timelineFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:timeline — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await timelineAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:timeline — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await timelineAs(SUB_NO_ASSIGNMENT, PAT_A1, {}, timelineFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:timeline — a principal without patient:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = timelineFixtureDeps();
  const response = await timelineAs(SUB_NO_PERM, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getTimeline().length, 3);
});

await test('patients:timeline — a malformed patient id is indistinguishable from a missing resource (404)', async () => {
  const response = await timelineAs(SUB_CASHIER, 'not-a-uuid', {}, timelineFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('patients:timeline — a nonexistent patient returns 404 (existence never leaked)', async () => {
  const response = await timelineAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, timelineFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('patients:timeline — a cross-tenant patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedTimeline: [fullTimelineEntry(TL_1, { tenantId: 'org-b', patientId: PAT_B1 })] });
  const response = await timelineAs(SUB_CASHIER, PAT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:timeline — a cross-facility patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedTimeline: [fullTimelineEntry(TL_1, { patientId: PAT_A2 })] });
  const response = await timelineAs(SUB_CASHIER, PAT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:timeline — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsTimeline(
    timelineReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    timelineFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
  assert.equal(body.data[0].id, TL_1);
});

await test('patients:timeline — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await timelineAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-b' }, timelineFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:timeline — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await timelineAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Branch': 'br-b' }, timelineFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:timeline — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = timelineFixtureDeps();
  const response = await timelineAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
});

await test('patients:timeline — correlation id propagates to the response', async () => {
  const response = await timelineAs(SUB_CASHIER, PAT_A1, { 'X-Correlation-Id': 'corr-timeline-1' }, timelineFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-timeline-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-timeline-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('patients:timeline — a generated correlation id echoes on success and errors', async () => {
  const ok = await timelineAs(SUB_CASHIER, PAT_A1, {}, timelineFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await timelineAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, timelineFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* PHASE 27 — appointments:queue (the live front-desk queue read)       */
/* ================================================================== */

function queueReq(query = '', headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/appointments-queue${query === '' ? '' : `?${query}`}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function queueAs(sub, query = '', headers = {}, deps = makeDeps()) {
  return handleAppointmentsQueue(
    queueReq(query, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// Four fac-a1 appointments on SLOT_DATE (2026-03-02), seeded OUT of token
// order: two checked_in + one in_consultation (the queue statuses) with
// token numbers, one booked (status filter) and one checked_in with a NULL
// token (the ASC NULLS LAST edge). ENC_A1 binds to APPT_A1.
function queueFixtures() {
  return makeDeps({}, {
    seedAppointments: [
      fullAppointment(APPT_A1, { status: 'checked_in', tokenNo: 3, startsAt: '2026-03-02T09:00:00Z' }),
      fullAppointment(APPT_A2, { status: 'in_consultation', tokenNo: 1, startsAt: '2026-03-02T09:00:00Z' }),
      fullAppointment(APPT_A1_DAY2, { status: 'checked_in', tokenNo: 2, startsAt: '2026-03-02T09:00:00Z' }),
      fullAppointment(APPT_A2_OTHER_FAC, { status: 'checked_in', tokenNo: 9, startsAt: '2026-03-02T09:00:00Z' }),
      fullAppointment(APPT_B1, { status: 'booked', tokenNo: 4, startsAt: '2026-03-02T09:00:00Z' }),
      fullAppointment('aaaaaaaa-0000-4000-8000-000000000a06', { status: 'checked_in', tokenNo: null, startsAt: '2026-03-02T09:00:00Z' }),
    ],
    seedEncounters: [fullEncounter(ENC_A1)],
  });
}

// The exact AppointmentController::queue entry key set — nothing else ever
// leaves the handler.
const QUEUE_ENTRY_KEYS = ['appointmentId', 'encounterId', 'patient', 'startsAt', 'status', 'tokenNo'];
const QUEUE_PATIENT_KEYS = ['fullName', 'id', 'mrn'];

await test('appointments:queue — a cashier reads the in-scope queue (200, exact shape, patient ref, encounter id)', async () => {
  const deps = queueFixtures();
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare queue list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 5);
  assert.deepEqual(Object.keys(body.data[0]).sort(), QUEUE_ENTRY_KEYS.slice().sort());
  assert.equal(body.data[0].appointmentId, APPT_A2);
  assert.equal(body.data[0].tokenNo, 1);
  assert.equal(body.data[0].status, 'in_consultation');
  assert.deepEqual(Object.keys(body.data[0].patient).sort(), QUEUE_PATIENT_KEYS.slice().sort());
  assert.equal(body.data[0].patient.id, PAT_A1);
  assert.equal(body.data[0].patient.mrn, 'MRN-A1-001');
  assert.equal(body.data[0].patient.fullName, 'Aarav Shrestha');
  assert.equal(body.data[0].startsAt, '2026-03-02T09:00:00Z');
  assert.equal(body.data[0].encounterId, null);
  // The APPT_A1 entry carries its encounter id.
  const encEntry = body.data.find((e) => e.appointmentId === APPT_A1);
  assert.equal(encEntry.encounterId, ENC_A1);
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('appointments:queue — entries are ordered by token_no ascending (NULLS LAST)', async () => {
  const body = await bodyJson(await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, queueFixtures()));
  assert.equal(body.data.length, 5);
  // Seeded out of order — the read orders by token_no asc; the null-token
  // entry sorts LAST (the PostgreSQL ASC NULLS LAST parity).
  const tokens = body.data.map((e) => e.tokenNo);
  assert.deepEqual(tokens, [1, 2, 3, 9, null]);
});

await test('appointments:queue — only checked_in / in_consultation statuses return', async () => {
  const body = await bodyJson(await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, queueFixtures()));
  const statuses = body.data.map((e) => e.status);
  assert.equal(statuses.includes('booked'), false);
  assert.equal(statuses.includes('checked_in'), true);
  assert.equal(statuses.includes('in_consultation'), true);
});

await test('appointments:queue — an absent date defaults to the server-side today (Laravel parity)', async () => {
  const body = await bodyJson(await queueAs(SUB_CASHIER, '', {}, queueFixtures()));
  // todayIso = SLOT_DATE (2026-03-02) — the same date as the fixtures.
  assert.equal(body.data.length, 5);
});

await test('appointments:queue — the providerStaffId filter narrows to one provider', async () => {
  const body = await bodyJson(await queueAs(SUB_CASHIER, 'date=2026-03-02&providerStaffId=' + STAFF_A2, {}, queueFixtures()));
  assert.equal(body.data.length, 0);
});

await test('appointments:queue — a date with no live visits returns an empty queue', async () => {
  const deps = queueFixtures();
  const body = await bodyJson(await queueAs(SUB_CASHIER, 'date=2026-03-03', {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('appointments:queue — the read mutates nothing and audits nothing (Laravel parity)', async () => {
  const deps = queueFixtures();
  await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, deps);
  // No mutation: every appointment status is untouched.
  assert.equal(deps.getAppointments().length, 6);
  assert.equal(deps.getAppointments().find((a) => a.id === APPT_A2).status, 'in_consultation');
  assert.equal(deps.getAppointments().find((a) => a.id === APPT_B1).status, 'booked');
  // NO audit — AppointmentController::queue records no audit event.
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('appointments:queue — internal appointment fields never leak into the data payload', async () => {
  const body = await bodyJson(await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, queueFixtures()));
  const raw = JSON.stringify(body.data);
  // Only the six approved entry fields; tenant/facility/patient/encounter
  // binding ids (except the presented encounterId), provider, service,
  // timestamps, and lock version never leave the store.
  assert.equal(raw.includes('tenantId'), false);
  assert.equal(raw.includes('facilityId'), false);
  assert.equal(raw.includes('patientId'), false);
  assert.equal(raw.includes('providerStaffId'), false);
  assert.equal(raw.includes('serviceId'), false);
  assert.equal(raw.includes('cancelReason'), false);
  assert.equal(raw.includes('lockVersion'), false);
  assert.equal(raw.includes('createdAt'), false);
});

await test('appointments:queue — missing Authorization is rejected (401)', async () => {
  const response = await handleAppointmentsQueue(queueReq('date=2026-03-02'), queueFixtures());
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:queue — an invalid JWT is rejected (401)', async () => {
  const response = await handleAppointmentsQueue(
    queueReq('date=2026-03-02', { Authorization: 'Bearer not-a-jwt' }),
    queueFixtures(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('appointments:queue — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handleAppointmentsQueue(
    queueReq('date=2026-03-02', { Authorization: `Bearer ${token}` }),
    queueFixtures(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('appointments:queue — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handleAppointmentsQueue(
    queueReq('date=2026-03-02', { Authorization: `Bearer ${token}` }),
    queueFixtures(),
  );
  assert.equal(response.status, 401);
});

await test('appointments:queue — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await queueAs(SUB_LOCKED, 'date=2026-03-02', {}, queueFixtures());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:queue — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await queueAs(SUB_DISABLED, 'date=2026-03-02', {}, queueFixtures());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:queue — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('appointments:queue — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await queueAs(SUB_NO_ASSIGNMENT, 'date=2026-03-02', {}, queueFixtures());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('appointments:queue — a principal without queue:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = queueFixtures();
  const response = await queueAs(SUB_RECEPTIONIST, 'date=2026-03-02', {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getAppointments().length, 6);
});

await test('appointments:queue — a malformed date 500s (Laravel PG-cast parity)', async () => {
  const response = await queueAs(SUB_CASHIER, 'date=not-a-date', {}, queueFixtures());
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
});

await test('appointments:queue — a malformed providerStaffId 500s (Laravel PG-cast parity)', async () => {
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02&providerStaffId=nope', {}, queueFixtures());
  assert.equal(response.status, 500);
  assert.equal((await bodyJson(response)).error.code, 'SERVER_ERROR');
});

await test('appointments:queue — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handleAppointmentsQueue(
    queueReq('date=2026-03-02', { Authorization: `Bearer ${token}` }),
    queueFixtures(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 5);
});

await test('appointments:queue — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02', { 'X-Swasthya-Facility': 'fac-b' }, queueFixtures());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('appointments:queue — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02', { 'X-Swasthya-Branch': 'br-b' }, queueFixtures());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('appointments:queue — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = queueFixtures();
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02', { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 5);
});

await test('appointments:queue — an org-level claim sees every tenant facility (facilityClause parity)', async () => {
  const deps = makeDeps({
    loadActiveAssignments: (userId) => userId === 'u-receptionist'
      ? [{
          id: 'as-q', userId: 'u-receptionist', roleId: 'r-org-admin', tenantId: 'org-a', facilityId: null,
          branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: [{ code: 'queue:view', scope: 'tenant' }] },
        }]
      : [],
  }, {
    seedAppointments: [
      fullAppointment(APPT_A1, { status: 'checked_in', tokenNo: 1, startsAt: '2026-03-02T09:00:00Z' }),
      fullAppointment(APPT_A2_OTHER_FAC, { facilityId: 'fac-a2', status: 'checked_in', tokenNo: 2, startsAt: '2026-03-02T09:00:00Z' }),
    ],
  });
  const response = await queueAs(SUB_RECEPTIONIST, 'date=2026-03-02', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  // fac-a1 + fac-a2 both visible under the org-level claim (facility null).
  assert.deepEqual(body.data.map((e) => e.appointmentId), [APPT_A1, APPT_A2_OTHER_FAC]);
  assert.equal(body.meta.context.facilityId, null);
});

await test('appointments:queue — correlation id propagates to the response', async () => {
  const response = await queueAs(SUB_CASHIER, 'date=2026-03-02', { 'X-Correlation-Id': 'corr-queue-1' }, queueFixtures());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-queue-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-queue-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('appointments:queue — a generated correlation id echoes on success and errors', async () => {
  const ok = await queueAs(SUB_CASHIER, 'date=2026-03-02', {}, queueFixtures());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await queueAs(SUB_CASHIER, 'date=not-a-date', {}, queueFixtures());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

/* PHASE 28 — patients:identifiers (the patient identity-document read) */
/* ================================================================== */

function identifiersReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-identifiers/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function identifiersAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsIdentifiers(
    identifiersReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const IDENT_NID = 'aaaaaaaa-0000-4000-8000-00000000d001';
const IDENT_PASSPORT = 'aaaaaaaa-0000-4000-8000-00000000d002';
const IDENT_LICENSE = 'aaaaaaaa-0000-4000-8000-00000000d003';

// The stored identifier row carries the internal fields the read must never
// present (tenantId/patientId/valueEncrypted/valueHash/createdAt).
function fullPatientIdentifier(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', patientId: PAT_A1,
    type: 'national_id', value: '0000-1111-2222',
    valueEncrypted: 'cipher:v1:abc', valueHash: 'abc'.padEnd(64, '0'),
    issuingCountry: 'NP', isVerified: false, status: 'active',
    createdAt: '2026-03-02T10:00:00Z',
    ...overrides,
  };
}

// Three identifiers on PAT_A1 (org-a / fac-a1), seeded OUT of created_at
// order — the read must order by created_at DESC (the exact Laravel
// `->orderByDesc('created_at')`). Distinct types cover the schema type
// check; one superseded row proves the NO status filter.
function threeIdentifiers() {
  return [
    fullPatientIdentifier(IDENT_NID, { type: 'national_id', value: '0000-1111-2222', issuingCountry: 'NP', isVerified: false, createdAt: '2026-03-02T09:00:00Z' }),
    fullPatientIdentifier(IDENT_PASSPORT, { type: 'passport', value: 'P1234567', issuingCountry: 'NP', isVerified: true, createdAt: '2026-03-02T11:00:00Z' }),
    fullPatientIdentifier(IDENT_LICENSE, { type: 'license', value: 'DL-88-2211', issuingCountry: null, isVerified: false, status: 'superseded', createdAt: '2026-03-02T10:00:00Z' }),
  ];
}

function identifiersFixtureDeps({ identifiers } = {}) {
  return makeDeps({}, { seedIdentifiers: identifiers === undefined ? threeIdentifiers() : identifiers });
}

// The exact PatientIdentifierController::index item key set — nothing else
// ever leaves the handler.
const IDENTIFIER_ITEM_KEYS = ['id', 'isVerified', 'issuingCountry', 'status', 'type', 'value'];

await test('patients:identifiers — a cashier reads the in-scope patient\'s identifiers (200, exact shape, decrypted value)', async () => {
  const deps = identifiersFixtureDeps();
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare identifier list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 3);
  // The exact 6-field map + key casing; internal fields never leak.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), IDENTIFIER_ITEM_KEYS.slice().sort());
  }
  const passport = body.data.find((i) => i.type === 'passport');
  assert.equal(passport.id, IDENT_PASSPORT);
  // The cast boundary: value is the decrypted plaintext, not ciphertext.
  assert.equal(passport.value, 'P1234567');
  assert.equal(passport.issuingCountry, 'NP');
  assert.equal(passport.isVerified, true);
  assert.equal(passport.status, 'active');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:identifiers — identifiers are ordered by created_at DESC (Laravel parity)', async () => {
  const body = await bodyJson(await identifiersAs(SUB_CASHIER, PAT_A1, {}, identifiersFixtureDeps()));
  assert.equal(body.data.length, 3);
  // Seeded out of order — the read orders by created_at DESC only.
  assert.equal(body.data[0].id, IDENT_PASSPORT);
  assert.equal(body.data[0].type, 'passport');
  assert.equal(body.data[1].id, IDENT_LICENSE);
  assert.equal(body.data[1].type, 'license');
  assert.equal(body.data[2].id, IDENT_NID);
  assert.equal(body.data[2].type, 'national_id');
});

await test('patients:identifiers — NO status filter: active AND superseded both return', async () => {
  const body = await bodyJson(await identifiersAs(SUB_CASHIER, PAT_A1, {}, identifiersFixtureDeps()));
  const statuses = body.data.map((i) => i.status).sort();
  assert.deepEqual(statuses, ['active', 'active', 'superseded']);
  const superseded = body.data.find((i) => i.status === 'superseded');
  assert.equal(superseded.id, IDENT_LICENSE);
});

await test('patients:identifiers — a nullable issuingCountry renders null (never a leak)', async () => {
  const body = await bodyJson(await identifiersAs(SUB_CASHIER, PAT_A1, {}, identifiersFixtureDeps()));
  const license = body.data.find((i) => i.type === 'license');
  assert.equal(license.issuingCountry, null);
  assert.equal(Object.keys(license).includes('issuingCountry'), true);
});

await test('patients:identifiers — an empty identifier set is an empty array (200)', async () => {
  const deps = makeDeps({}, { seedIdentifiers: [] });
  const body = await bodyJson(await identifiersAs(SUB_CASHIER, PAT_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('patients:identifiers — an unauthenticated request is rejected (401)', async () => {
  const response = await handlePatientsIdentifiers(
    identifiersReq(PAT_A1, { Authorization: 'Bearer not-a-jwt' }),
    identifiersFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:identifiers — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handlePatientsIdentifiers(
    identifiersReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    identifiersFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:identifiers — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsIdentifiers(
    identifiersReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    identifiersFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:identifiers — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await identifiersAs(SUB_LOCKED, PAT_A1, {}, identifiersFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:identifiers — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await identifiersAs(SUB_DISABLED, PAT_A1, {}, identifiersFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:identifiers — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:identifiers — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await identifiersAs(SUB_NO_ASSIGNMENT, PAT_A1, {}, identifiersFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:identifiers — a principal without patient:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = identifiersFixtureDeps();
  const response = await identifiersAs(SUB_NO_PERM, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getIdentifiers().length, 3);
});

await test('patients:identifiers — a malformed patient id is indistinguishable from a missing resource (404)', async () => {
  const response = await identifiersAs(SUB_CASHIER, 'not-a-uuid', {}, identifiersFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('patients:identifiers — a nonexistent patient returns 404 (existence never leaked)', async () => {
  const response = await identifiersAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, identifiersFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('patients:identifiers — a cross-tenant patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedIdentifiers: [fullPatientIdentifier(IDENT_NID, { tenantId: 'org-b', patientId: PAT_B1 })] });
  const response = await identifiersAs(SUB_CASHIER, PAT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:identifiers — a cross-facility patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedIdentifiers: [fullPatientIdentifier(IDENT_NID, { patientId: PAT_A2 })] });
  const response = await identifiersAs(SUB_CASHIER, PAT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:identifiers — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsIdentifiers(
    identifiersReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    identifiersFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
  assert.equal(body.data[0].id, IDENT_PASSPORT);
});

await test('patients:identifiers — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-b' }, identifiersFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:identifiers — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Branch': 'br-b' }, identifiersFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:identifiers — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = identifiersFixtureDeps();
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
});

await test('patients:identifiers — correlation id propagates to the response', async () => {
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, { 'X-Correlation-Id': 'corr-ident-1' }, identifiersFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-ident-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-ident-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('patients:identifiers — a generated correlation id echoes on success and errors', async () => {
  const ok = await identifiersAs(SUB_CASHIER, PAT_A1, {}, identifiersFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await identifiersAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, identifiersFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

await test('patients:identifiers — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = identifiersFixtureDeps();
  const before = deps.getIdentifiers().map((i) => ({ id: i.id, value: i.value, status: i.status }));
  const response = await identifiersAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getIdentifiers().map((i) => ({ id: i.id, value: i.value, status: i.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
  // Internal fields never surface in the payload.
  const body = await bodyJson(response);
  const allKeys = new Set(body.data.flatMap((i) => Object.keys(i)));
  for (const forbidden of ['tenantId', 'patientId', 'valueEncrypted', 'valueHash', 'createdAt', 'createdBy', 'verifiedBy', 'verifiedAt']) {
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

/* PHASE 29 — patients:contacts (the patient contact read)             */
/* ================================================================== */

function contactsReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-contacts/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function contactsAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsContacts(
    contactsReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const CONTACT_PHONE = 'aaaaaaaa-0000-4000-8000-00000000e001';
const CONTACT_ADDRESS = 'aaaaaaaa-0000-4000-8000-00000000e002';
const CONTACT_EMAIL = 'aaaaaaaa-0000-4000-8000-00000000e003';
const CONTACT_EMERGENCY = 'aaaaaaaa-0000-4000-8000-00000000e004';

// The stored contact row carries the internal fields the read must never
// present (tenantId/patientId/validFrom/validTo/createdAt/createdBy/
// updatedBy). `value`/`address`/`contactPerson` ARE the public contract.
function fullPatientContact(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', patientId: PAT_A1,
    type: 'phone', value: '+977-9800000000',
    address: null, contactPerson: null,
    isPrimary: false, status: 'active',
    validFrom: null, validTo: null,
    createdAt: '2026-03-02T10:00:00Z', createdBy: null, updatedBy: null,
    ...overrides,
  };
}

// Four contacts on PAT_A1 (org-a / fac-a1), seeded OUT of created_at order
// with one superseded row (NO status filter) — the read must order by
// is_primary DESC then created_at ASC (the exact Laravel
// `->orderByDesc('is_primary')->orderBy('created_at')`). Distinct types
// cover the schema type check; the emergency row carries the contactPerson
// payload; the address row the address payload (value/address XOR per the
// CHECK constraint — one of them is always null).
function fourContacts() {
  return [
    fullPatientContact(CONTACT_EMAIL, { type: 'email', value: 'aarav@example.com', createdAt: '2026-03-02T11:00:00Z' }),
    fullPatientContact(CONTACT_ADDRESS, { type: 'address', value: null, address: { street: 'Durbar Marg', city: 'Kathmandu' }, createdAt: '2026-03-02T10:00:00Z' }),
    fullPatientContact(CONTACT_EMERGENCY, { type: 'emergency_contact', value: '+977-9811111111', contactPerson: { name: 'Sita Shrestha', relation: 'spouse' }, createdAt: '2026-03-02T12:00:00Z' }),
    fullPatientContact(CONTACT_PHONE, { type: 'phone', value: '+977-9800000000', isPrimary: true, status: 'active', createdAt: '2026-03-02T09:00:00Z' }),
  ];
}

function contactsFixtureDeps({ contacts } = {}) {
  return makeDeps({}, { seedContacts: contacts === undefined ? fourContacts() : contacts });
}

// The exact PatientContactController::present() key set — nothing else ever
// leaves the handler.
const CONTACT_ITEM_KEYS = ['address', 'contactPerson', 'id', 'isPrimary', 'status', 'type', 'value'];

await test('patients:contacts — a cashier reads the in-scope patient\'s contacts (200, exact shape)', async () => {
  const deps = contactsFixtureDeps();
  const response = await contactsAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare contact list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 4);
  // The exact 7-field map + key casing; internal fields never leak.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), CONTACT_ITEM_KEYS.slice().sort());
  }
  const phone = body.data[0];
  assert.equal(phone.id, CONTACT_PHONE);
  assert.equal(phone.type, 'phone');
  assert.equal(phone.value, '+977-9800000000');
  assert.equal(phone.address, null);
  assert.equal(phone.contactPerson, null);
  assert.equal(phone.isPrimary, true);
  assert.equal(phone.status, 'active');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:contacts — contacts are ordered by is_primary DESC then created_at ASC (Laravel parity)', async () => {
  const body = await bodyJson(await contactsAs(SUB_CASHIER, PAT_A1, {}, contactsFixtureDeps()));
  assert.equal(body.data.length, 4);
  // Seeded out of order — the primary dominates, then created_at ASC:
  // phone (primary, 09:00) → address (10:00) → email (11:00) →
  // emergency (12:00). The emergency row has the LATEST created_at but is
  // non-primary — proving is_primary DESC dominates.
  assert.deepEqual(body.data.map((c) => c.id), [CONTACT_PHONE, CONTACT_ADDRESS, CONTACT_EMAIL, CONTACT_EMERGENCY]);
  assert.equal(body.data[0].isPrimary, true);
  assert.equal(body.data[1].isPrimary, false);
  assert.equal(body.data[2].isPrimary, false);
  assert.equal(body.data[3].isPrimary, false);
});

await test('patients:contacts — NO status filter: active AND superseded both return', async () => {
  const deps = makeDeps({}, { seedContacts: [
    fullPatientContact(CONTACT_PHONE, { isPrimary: true, status: 'active' }),
    fullPatientContact(CONTACT_ADDRESS, { type: 'address', value: null, address: { street: 'X' }, status: 'superseded' }),
  ] });
  const body = await bodyJson(await contactsAs(SUB_CASHIER, PAT_A1, {}, deps));
  const statuses = body.data.map((c) => c.status).sort();
  assert.deepEqual(statuses, ['active', 'superseded']);
  const superseded = body.data.find((c) => c.status === 'superseded');
  assert.equal(superseded.id, CONTACT_ADDRESS);
});

await test('patients:contacts — nullable fields render per the contract (value/address XOR, contactPerson)', async () => {
  const body = await bodyJson(await contactsAs(SUB_CASHIER, PAT_A1, {}, contactsFixtureDeps()));
  const address = body.data.find((c) => c.type === 'address');
  const emergency = body.data.find((c) => c.type === 'emergency_contact');
  const email = body.data.find((c) => c.type === 'email');
  // The address row: value null, address the decoded jsonb payload.
  assert.equal(address.value, null);
  assert.deepEqual(address.address, { street: 'Durbar Marg', city: 'Kathmandu' });
  assert.equal(address.contactPerson, null);
  // The emergency row: the contactPerson payload.
  assert.deepEqual(emergency.contactPerson, { name: 'Sita Shrestha', relation: 'spouse' });
  // The email row: value set, address null (the XOR contract).
  assert.equal(email.value, 'aarav@example.com');
  assert.equal(email.address, null);
});

await test('patients:contacts — an empty contact set is an empty array (200)', async () => {
  const deps = makeDeps({}, { seedContacts: [] });
  const body = await bodyJson(await contactsAs(SUB_CASHIER, PAT_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('patients:contacts — an unauthenticated request is rejected (401)', async () => {
  const response = await handlePatientsContacts(
    contactsReq(PAT_A1, { Authorization: 'Bearer not-a-jwt' }),
    contactsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:contacts — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handlePatientsContacts(
    contactsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    contactsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:contacts — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsContacts(
    contactsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    contactsFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:contacts — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await contactsAs(SUB_LOCKED, PAT_A1, {}, contactsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:contacts — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await contactsAs(SUB_DISABLED, PAT_A1, {}, contactsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:contacts — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await contactsAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:contacts — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await contactsAs(SUB_NO_ASSIGNMENT, PAT_A1, {}, contactsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:contacts — a principal without patient:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = contactsFixtureDeps();
  const response = await contactsAs(SUB_NO_PERM, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getContacts().length, 4);
});

await test('patients:contacts — a malformed patient id is indistinguishable from a missing resource (404)', async () => {
  const response = await contactsAs(SUB_CASHIER, 'not-a-uuid', {}, contactsFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('patients:contacts — a nonexistent patient returns 404 (existence never leaked)', async () => {
  const response = await contactsAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, contactsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('patients:contacts — a cross-tenant patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedContacts: [fullPatientContact(CONTACT_PHONE, { tenantId: 'org-b', patientId: PAT_B1 })] });
  const response = await contactsAs(SUB_CASHIER, PAT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:contacts — a cross-facility patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedContacts: [fullPatientContact(CONTACT_PHONE, { patientId: PAT_A2 })] });
  const response = await contactsAs(SUB_CASHIER, PAT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:contacts — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsContacts(
    contactsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    contactsFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 4);
  assert.equal(body.data[0].id, CONTACT_PHONE);
});

await test('patients:contacts — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await contactsAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-b' }, contactsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:contacts — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await contactsAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Branch': 'br-b' }, contactsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:contacts — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = contactsFixtureDeps();
  const response = await contactsAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 4);
});

await test('patients:contacts — correlation id propagates to the response', async () => {
  const response = await contactsAs(SUB_CASHIER, PAT_A1, { 'X-Correlation-Id': 'corr-contact-1' }, contactsFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-contact-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-contact-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('patients:contacts — a generated correlation id echoes on success and errors', async () => {
  const ok = await contactsAs(SUB_CASHIER, PAT_A1, {}, contactsFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await contactsAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, contactsFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

await test('patients:contacts — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = contactsFixtureDeps();
  const before = deps.getContacts().map((c) => ({ id: c.id, value: c.value, isPrimary: c.isPrimary, status: c.status }));
  const response = await contactsAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getContacts().map((c) => ({ id: c.id, value: c.value, isPrimary: c.isPrimary, status: c.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
  // Internal fields never surface in the payload.
  const body = await bodyJson(response);
  const allKeys = new Set(body.data.flatMap((c) => Object.keys(c)));
  for (const forbidden of ['tenantId', 'patientId', 'validFrom', 'validTo', 'createdAt', 'createdBy', 'updatedBy']) {
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

/* PHASE 30 — patients:insurance-policies (the patient insurance read)  */
/* ================================================================== */

function policiesReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-insurance-policies/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function policiesAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsInsurancePolicies(
    policiesReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const PYR_A = 'aaaaaaaa-0000-4000-8000-00000000f001';
const PYR_B = 'aaaaaaaa-0000-4000-8000-00000000f002';
const POLICY_ACTIVE = 'aaaaaaaa-0000-4000-8000-00000000f011';
const POLICY_CANCELLED = 'aaaaaaaa-0000-4000-8000-00000000f012';
const POLICY_EXPIRED = 'aaaaaaaa-0000-4000-8000-00000000f013';

// The stored policy row carries the internal fields the read must never
// present (tenantId/createdAt/createdBy/updatedBy). patientId/payerId/
// lockVersion ARE contract-explicit (the exact Laravel present() map).
function fullInsurancePolicy(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', patientId: PAT_A1, payerId: PYR_A,
    policyNumber: 'POL-A1-0001', coverageType: 'general',
    validFrom: '2026-01-01', validTo: '2027-01-01',
    benefits: { coverage: 80, maxPerVisit: 5000 },
    status: 'active', lockVersion: 0,
    createdAt: '2026-03-02T10:00:00Z', createdBy: null, updatedBy: null,
    ...overrides,
  };
}

// Payer master rows (mirror of the payers store — payers is TENANT_ONLY;
// PYR_B lives in org-b so a policy referencing it renders a NULL payer ref,
// never a leak).
function defaultPayers() {
  return [
    { id: PYR_A, tenantId: 'org-a', name: 'National Insurance', code: 'NIC' },
    { id: PYR_B, tenantId: 'org-b', name: 'Other Tenant Payer', code: 'OTP' },
  ];
}

// Three policies on PAT_A1 (org-a / fac-a1), seeded OUT of created_at order
// with three distinct statuses — the read must order by created_at DESC
// (the exact Laravel `->orderByDesc('created_at')`) and apply NO status
// filter (active / cancelled / expired all return).
function threePolicies() {
  return [
    fullInsurancePolicy(POLICY_CANCELLED, { policyNumber: 'POL-A1-0002', status: 'cancelled', validTo: null, createdAt: '2026-03-02T10:00:00Z' }),
    fullInsurancePolicy(POLICY_EXPIRED, { policyNumber: 'POL-A1-0003', coverageType: 'accident', status: 'expired', validTo: '2026-02-01', createdAt: '2026-03-02T09:00:00Z' }),
    fullInsurancePolicy(POLICY_ACTIVE, { policyNumber: 'POL-A1-0001', coverageType: 'general', status: 'active', validTo: '2027-01-01', createdAt: '2026-03-02T11:00:00Z' }),
  ];
}

function policiesFixtureDeps({ policies, payers } = {}) {
  return makeDeps({}, {
    seedPolicies: policies === undefined ? threePolicies() : policies,
    seedPayers: payers === undefined ? defaultPayers() : payers,
  });
}

// The exact InsurancePolicyController::present() key set — nothing else
// ever leaves the handler.
const POLICY_ITEM_KEYS = ['benefits', 'coverageType', 'id', 'lockVersion', 'patientId', 'payer', 'payerId', 'policyNumber', 'status', 'validFrom', 'validTo'];
const PAYER_REF_KEYS = ['code', 'id', 'name'];

await test('patients:insurance-policies — a cashier reads the in-scope patient\'s policies (200, exact shape, payer ref)', async () => {
  const deps = policiesFixtureDeps();
  const response = await policiesAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare policy list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 3);
  // The exact 11-field map + key casing; internal fields never leak.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), POLICY_ITEM_KEYS.slice().sort());
  }
  const active = body.data.find((p) => p.status === 'active');
  assert.equal(active.id, POLICY_ACTIVE);
  assert.equal(active.patientId, PAT_A1);
  assert.equal(active.payerId, PYR_A);
  assert.deepEqual(Object.keys(active.payer).sort(), PAYER_REF_KEYS.slice().sort());
  assert.equal(active.payer.name, 'National Insurance');
  assert.equal(active.payer.code, 'NIC');
  assert.equal(active.policyNumber, 'POL-A1-0001');
  assert.equal(active.coverageType, 'general');
  assert.equal(active.validFrom, '2026-01-01');
  assert.equal(active.validTo, '2027-01-01');
  assert.deepEqual(active.benefits, { coverage: 80, maxPerVisit: 5000 });
  assert.equal(active.lockVersion, 0);
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:insurance-policies — policies are ordered by created_at DESC (Laravel parity)', async () => {
  const body = await bodyJson(await policiesAs(SUB_CASHIER, PAT_A1, {}, policiesFixtureDeps()));
  assert.equal(body.data.length, 3);
  // Seeded out of order — the read orders by created_at DESC only.
  assert.deepEqual(body.data.map((p) => p.id), [POLICY_ACTIVE, POLICY_CANCELLED, POLICY_EXPIRED]);
  assert.equal(body.data[0].status, 'active');
  assert.equal(body.data[1].status, 'cancelled');
  assert.equal(body.data[2].status, 'expired');
});

await test('patients:insurance-policies — NO status filter: active, cancelled AND expired all return', async () => {
  const body = await bodyJson(await policiesAs(SUB_CASHIER, PAT_A1, {}, policiesFixtureDeps()));
  const statuses = body.data.map((p) => p.status).sort();
  assert.deepEqual(statuses, ['active', 'cancelled', 'expired']);
});

await test('patients:insurance-policies — an out-of-tenant payer renders a NULL payer ref (never a leak)', async () => {
  const deps = makeDeps({}, {
    seedPolicies: [fullInsurancePolicy(POLICY_ACTIVE, { payerId: PYR_B, status: 'active' })],
    seedPayers: defaultPayers(),
  });
  const body = await bodyJson(await policiesAs(SUB_CASHIER, PAT_A1, {}, deps));
  assert.equal(body.data.length, 1);
  assert.equal(body.data[0].payerId, PYR_B);
  // The payer lives in org-b — under org-a claims it renders null.
  assert.equal(body.data[0].payer, null);
});

await test('patients:insurance-policies — nullable fields render per the contract (validTo, benefits)', async () => {
  const body = await bodyJson(await policiesAs(SUB_CASHIER, PAT_A1, {}, policiesFixtureDeps()));
  const cancelled = body.data.find((p) => p.status === 'cancelled');
  assert.equal(cancelled.validTo, null);
  assert.deepEqual(cancelled.benefits, { coverage: 80, maxPerVisit: 5000 });
  const expired = body.data.find((p) => p.status === 'expired');
  assert.equal(expired.coverageType, 'accident');
  assert.equal(expired.validTo, '2026-02-01');
});

await test('patients:insurance-policies — an empty policy set is an empty array (200)', async () => {
  const deps = makeDeps({}, { seedPolicies: [], seedPayers: defaultPayers() });
  const body = await bodyJson(await policiesAs(SUB_CASHIER, PAT_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('patients:insurance-policies — an unauthenticated request is rejected (401)', async () => {
  const response = await handlePatientsInsurancePolicies(
    policiesReq(PAT_A1, { Authorization: 'Bearer not-a-jwt' }),
    policiesFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:insurance-policies — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_CASHIER, exp: NOW - 3600 });
  const response = await handlePatientsInsurancePolicies(
    policiesReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    policiesFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:insurance-policies — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsInsurancePolicies(
    policiesReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    policiesFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:insurance-policies — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await policiesAs(SUB_LOCKED, PAT_A1, {}, policiesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:insurance-policies — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await policiesAs(SUB_DISABLED, PAT_A1, {}, policiesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:insurance-policies — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_CASHIER ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await policiesAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:insurance-policies — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await policiesAs(SUB_NO_ASSIGNMENT, PAT_A1, {}, policiesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:insurance-policies — a principal without insurance:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = policiesFixtureDeps();
  const response = await policiesAs(SUB_NO_PERM, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getPolicies().length, 3);
});

await test('patients:insurance-policies — patient:view ALONE is not the gate: a doctor without insurance:view is denied (403)', async () => {
  // SUB_FAC_DOCTOR holds patient:view (and billing:view) but NOT
  // insurance:view — the gate is the exact Laravel `authorize:insurance:view`.
  const response = await policiesAs(SUB_FAC_DOCTOR, PAT_A1, {}, policiesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('patients:insurance-policies — a malformed patient id is indistinguishable from a missing resource (404)', async () => {
  const response = await policiesAs(SUB_CASHIER, 'not-a-uuid', {}, policiesFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('patients:insurance-policies — a nonexistent patient returns 404 (existence never leaked)', async () => {
  const response = await policiesAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, policiesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('patients:insurance-policies — a cross-tenant patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedPolicies: [fullInsurancePolicy(POLICY_ACTIVE, { tenantId: 'org-b', patientId: PAT_B1 })], seedPayers: defaultPayers() });
  const response = await policiesAs(SUB_CASHIER, PAT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:insurance-policies — a cross-facility patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedPolicies: [fullInsurancePolicy(POLICY_ACTIVE, { patientId: PAT_A2 })], seedPayers: defaultPayers() });
  const response = await policiesAs(SUB_CASHIER, PAT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:insurance-policies — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_CASHIER,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsInsurancePolicies(
    policiesReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    policiesFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
  assert.equal(body.data[0].id, POLICY_ACTIVE);
});

await test('patients:insurance-policies — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await policiesAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-b' }, policiesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:insurance-policies — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await policiesAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Branch': 'br-b' }, policiesFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:insurance-policies — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = policiesFixtureDeps();
  const response = await policiesAs(SUB_CASHIER, PAT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
});

await test('patients:insurance-policies — correlation id propagates to the response', async () => {
  const response = await policiesAs(SUB_CASHIER, PAT_A1, { 'X-Correlation-Id': 'corr-policy-1' }, policiesFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-policy-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-policy-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('patients:insurance-policies — a generated correlation id echoes on success and errors', async () => {
  const ok = await policiesAs(SUB_CASHIER, PAT_A1, {}, policiesFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await policiesAs(SUB_CASHIER, 'ffffffff-0000-4000-8000-000000000000', {}, policiesFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

await test('patients:insurance-policies — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = policiesFixtureDeps();
  const before = deps.getPolicies().map((p) => ({ id: p.id, policyNumber: p.policyNumber, status: p.status, lockVersion: p.lockVersion }));
  const response = await policiesAs(SUB_CASHIER, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getPolicies().map((p) => ({ id: p.id, policyNumber: p.policyNumber, status: p.status, lockVersion: p.lockVersion }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
  // Internal fields never surface in the payload (patientId/payerId/
  // lockVersion ARE contract-explicit — only tenant/actor/timestamp fields
  // are forbidden).
  const body = await bodyJson(response);
  const allKeys = new Set(body.data.flatMap((p) => Object.keys(p)));
  for (const forbidden of ['tenantId', 'facilityId', 'createdAt', 'createdBy', 'updatedBy', 'updatedAt']) {
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

/* PHASE 31 — patients:consents (the patient consent read)              */
/* ================================================================== */

function consentsReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-consents/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function consentsAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsConsents(
    consentsReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const CONSENT_TREATMENT = 'aaaaaaaa-0000-4000-8000-00000001a001';
const CONSENT_DATA_USE = 'aaaaaaaa-0000-4000-8000-00000001a002';
const CONSENT_TELEHEALTH = 'aaaaaaaa-0000-4000-8000-00000001a003';

// The stored consent row carries the internal fields the read must never
// present (tenantId/givenBy/revokedBy/documentId/createdAt/updatedAt).
// patientId IS contract-explicit (the exact Laravel present() map).
function fullPatientConsent(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', patientId: PAT_A1,
    consentType: 'treatment', version: 1, status: 'active',
    scope: { care: true },
    givenAt: '2026-03-02T10:00:00Z', revokedAt: null, revocationReason: null,
    givenBy: null, revokedBy: null, documentId: null,
    createdAt: '2026-03-02T10:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Three consents on PAT_A1 (org-a / fac-a1), seeded with DISTINCT versions
// — the read must order by version DESC (the exact Laravel
// `->orderByDesc('version')`, no secondary key). Three distinct types
// (schema check) and three distinct lifecycle statuses (NO status filter:
// active / expired / revoked all return; versioned history outlives the
// consent).
function threeConsents() {
  return [
    fullPatientConsent(CONSENT_TREATMENT, { consentType: 'treatment', version: 3, status: 'active', scope: { treatment: true, sharing: 'clinic' }, givenAt: '2026-03-02T11:00:00Z' }),
    fullPatientConsent(CONSENT_DATA_USE, { consentType: 'data_use', version: 2, status: 'expired', scope: { care: true }, givenAt: '2026-03-02T10:00:00Z' }),
    fullPatientConsent(CONSENT_TELEHEALTH, { consentType: 'telehealth', version: 1, status: 'revoked', scope: { telehealth: true }, givenAt: '2026-03-02T09:00:00Z', revokedAt: '2026-02-01T10:00:00Z', revocationReason: 'Patient request' }),
  ];
}

function consentsFixtureDeps({ consents } = {}) {
  return makeDeps({}, { seedConsents: consents === undefined ? threeConsents() : consents });
}

// The exact ConsentController::present() key set — nothing else ever leaves
// the handler.
const CONSENT_ITEM_KEYS = ['consentType', 'givenAt', 'id', 'patientId', 'revocationReason', 'revokedAt', 'scope', 'status', 'version'];

await test('patients:consents — a receptionist reads the in-scope patient\'s consents (200, exact shape)', async () => {
  const deps = consentsFixtureDeps();
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare consent list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 3);
  // The exact 9-field map + key casing; internal fields never leak.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), CONSENT_ITEM_KEYS.slice().sort());
  }
  const treatment = body.data[0];
  assert.equal(treatment.id, CONSENT_TREATMENT);
  assert.equal(treatment.patientId, PAT_A1);
  assert.equal(treatment.consentType, 'treatment');
  assert.equal(treatment.version, 3);
  assert.equal(treatment.status, 'active');
  assert.deepEqual(treatment.scope, { treatment: true, sharing: 'clinic' });
  assert.equal(treatment.givenAt, '2026-03-02T11:00:00Z');
  assert.equal(treatment.revokedAt, null);
  assert.equal(treatment.revocationReason, null);
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:consents — consents are ordered by version DESC (Laravel parity)', async () => {
  const body = await bodyJson(await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, consentsFixtureDeps()));
  assert.equal(body.data.length, 3);
  assert.deepEqual(body.data.map((c) => c.id), [CONSENT_TREATMENT, CONSENT_DATA_USE, CONSENT_TELEHEALTH]);
  assert.equal(body.data[0].version, 3);
  assert.equal(body.data[1].version, 2);
  assert.equal(body.data[2].version, 1);
});

await test('patients:consents — NO status filter: active, expired AND revoked all return', async () => {
  const body = await bodyJson(await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, consentsFixtureDeps()));
  const statuses = body.data.map((c) => c.status).sort();
  assert.deepEqual(statuses, ['active', 'expired', 'revoked']);
});

await test('patients:consents — nullable fields render per the contract (revokedAt, revocationReason)', async () => {
  const body = await bodyJson(await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, consentsFixtureDeps()));
  const revoked = body.data.find((c) => c.status === 'revoked');
  assert.equal(revoked.consentType, 'telehealth');
  assert.deepEqual(revoked.scope, { telehealth: true });
  assert.equal(revoked.revokedAt, '2026-02-01T10:00:00Z');
  assert.equal(revoked.revocationReason, 'Patient request');
  const expired = body.data.find((c) => c.status === 'expired');
  assert.equal(expired.revokedAt, null);
  assert.equal(expired.revocationReason, null);
  assert.deepEqual(expired.scope, { care: true });
});

await test('patients:consents — an empty consent set is an empty array (200)', async () => {
  const deps = makeDeps({}, { seedConsents: [] });
  const body = await bodyJson(await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('patients:consents — an unauthenticated request is rejected (401)', async () => {
  const response = await handlePatientsConsents(
    consentsReq(PAT_A1, { Authorization: 'Bearer not-a-jwt' }),
    consentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:consents — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_RECEPTIONIST, exp: NOW - 3600 });
  const response = await handlePatientsConsents(
    consentsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    consentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:consents — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsConsents(
    consentsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    consentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:consents — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await consentsAs(SUB_LOCKED, PAT_A1, {}, consentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:consents — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await consentsAs(SUB_DISABLED, PAT_A1, {}, consentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:consents — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_RECEPTIONIST ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:consents — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await consentsAs(SUB_NO_ASSIGNMENT, PAT_A1, {}, consentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:consents — a principal without consent:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = consentsFixtureDeps();
  const response = await consentsAs(SUB_NO_PERM, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getConsents().length, 3);
});

await test('patients:consents — patient:view/insurance:view ALONE is not the gate: a cashier without consent:view is denied (403)', async () => {
  // SUB_CASHIER holds patient:view + insurance:view but NOT consent:view
  // (the seeded billing_clerk role lacks consent:view) — the gate is the
  // exact Laravel `authorize:consent:view`.
  const response = await consentsAs(SUB_CASHIER, PAT_A1, {}, consentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('patients:consents — a malformed patient id is indistinguishable from a missing resource (404)', async () => {
  const response = await consentsAs(SUB_RECEPTIONIST, 'not-a-uuid', {}, consentsFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('patients:consents — a nonexistent patient returns 404 (existence never leaked)', async () => {
  const response = await consentsAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000', {}, consentsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('patients:consents — a cross-tenant patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedConsents: [fullPatientConsent(CONSENT_TREATMENT, { tenantId: 'org-b', patientId: PAT_B1 })] });
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:consents — a cross-facility patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedConsents: [fullPatientConsent(CONSENT_TREATMENT, { patientId: PAT_A2 })] });
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:consents — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_RECEPTIONIST,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsConsents(
    consentsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    consentsFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
  assert.equal(body.data[0].id, CONSENT_TREATMENT);
});

await test('patients:consents — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Swasthya-Facility': 'fac-b' }, consentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:consents — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Swasthya-Branch': 'br-b' }, consentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:consents — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = consentsFixtureDeps();
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 3);
});

await test('patients:consents — correlation id propagates to the response', async () => {
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Correlation-Id': 'corr-consent-1' }, consentsFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-consent-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-consent-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('patients:consents — a generated correlation id echoes on success and errors', async () => {
  const ok = await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, consentsFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await consentsAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000', {}, consentsFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

await test('patients:consents — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = consentsFixtureDeps();
  const before = deps.getConsents().map((c) => ({ id: c.id, status: c.status, version: c.version, scope: c.scope }));
  const response = await consentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getConsents().map((c) => ({ id: c.id, status: c.status, version: c.version, scope: c.scope }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
  // Internal fields never surface in the payload (patientId IS the
  // contract — only tenant/actor/document/timestamp fields are forbidden).
  const body = await bodyJson(response);
  const allKeys = new Set(body.data.flatMap((c) => Object.keys(c)));
  for (const forbidden of ['tenantId', 'facilityId', 'givenBy', 'revokedBy', 'documentId', 'createdAt', 'updatedAt']) {
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

/* PHASE 32 — patients:documents (the patient document-metadata read)   */
/* ================================================================== */

function documentsReq(id, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/patients-documents/${id}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function documentsAs(sub, id, headers = {}, deps = makeDeps()) {
  return handlePatientsDocuments(
    documentsReq(id, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

const DOC_REPORT = 'aaaaaaaa-0000-4000-8000-00000001b001';
const DOC_CONSENT = 'aaaaaaaa-0000-4000-8000-00000001b002';
const DOC_ID = 'aaaaaaaa-0000-4000-8000-00000001b003';
const DOC_DISCHARGE = 'aaaaaaaa-0000-4000-8000-00000001b004';

// The stored document row carries the internal fields the read must never
// present (tenantId/objectKey/uploadedBy/parentDocumentId/createdAt/
// updatedAt). patientId IS contract-explicit (the exact Laravel present()
// map); `objectKey` is the storage pointer the Laravel contract DELIBERATELY
// does not present — it must never cross this boundary.
function fullPatientDocument(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', patientId: PAT_A1,
    documentType: 'report', mimeType: 'application/pdf',
    sizeBytes: 1048576, checksum: 'a1b2c3d4e5f6'.padEnd(64, '0'),
    status: 'staged',
    uploadedAt: '2026-03-02T10:00:00Z', expiresAt: null, retentionClass: 'clinical',
    objectKey: 'patients/'.concat(PAT_A1, '/', id), uploadedBy: null,
    parentDocumentId: null, createdAt: '2026-03-02T10:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Four documents on PAT_A1 (org-a / fac-a1), seeded OUT of created_at order
// — the read must order by created_at DESC (the exact Laravel
// `->orderByDesc('created_at')`, no secondary key). Four distinct types
// (schema check) and the FOUR lifecycle statuses (NO status filter:
// staged / available / archived / purged all return). The discharge row is
// fully-nullable metadata (the nullability contract).
function fourDocuments() {
  return [
    fullPatientDocument(DOC_REPORT, { documentType: 'report', status: 'staged', mimeType: 'application/pdf', sizeBytes: 1048576, checksum: 'a1b2c3d4e5f6'.padEnd(64, '0'), retentionClass: 'clinical', uploadedAt: '2026-03-02T11:00:00Z', createdAt: '2026-03-02T11:00:00Z' }),
    fullPatientDocument(DOC_CONSENT, { documentType: 'consent', status: 'available', mimeType: 'application/pdf', sizeBytes: 204800, checksum: 'c3d4e5f6a7b8'.padEnd(64, '0'), expiresAt: '2027-06-01T00:00:00Z', retentionClass: 'legal', uploadedAt: '2026-03-02T10:30:00Z', createdAt: '2026-03-02T10:30:00Z' }),
    fullPatientDocument(DOC_ID, { documentType: 'id', status: 'archived', mimeType: 'image/png', sizeBytes: 51200, checksum: 'e5f6a7b8c9d0'.padEnd(64, '0'), retentionClass: 'identity', uploadedAt: '2026-03-02T10:00:00Z', createdAt: '2026-03-02T10:00:00Z' }),
    fullPatientDocument(DOC_DISCHARGE, { documentType: 'discharge', status: 'purged', mimeType: null, sizeBytes: null, checksum: null, expiresAt: null, retentionClass: null, uploadedAt: '2026-03-02T09:00:00Z', createdAt: '2026-03-02T09:00:00Z' }),
  ];
}

function documentsFixtureDeps({ documents } = {}) {
  return makeDeps({}, { seedDocuments: documents === undefined ? fourDocuments() : documents });
}

// The exact PatientDocumentController::present() key set — nothing else ever
// leaves the handler.
const DOCUMENT_ITEM_KEYS = ['checksum', 'documentType', 'expiresAt', 'id', 'mimeType', 'patientId', 'retentionClass', 'sizeBytes', 'status', 'uploadedAt'];

// --- organizations:departments fixtures (Phase 33) ----------------------

const DEPT_CARD = 'dept-a1';
const DEPT_ER = 'dept-a2';
const DEPT_LAB = 'dept-a3';
const DEPT_SUR = 'dept-a4';
const DEPT_A1_OTHERBR = 'dept-a5';
const DEPT_B_ONC = 'dept-b1';

function fullDepartment(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', branchId: 'br-a1',
    name: 'Cardiology', code: 'CARD', status: 'active',
    parentDepartmentId: null, createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Six departments across the scope matrix: org-a/fac-a1/br-a1 rows (one
// with a NULL branch — the org/facility-level row — and one carrying an
// INACTIVE status — the NO-status-filter proof), an org-a/fac-a1 row on a
// DIFFERENT fac-a1 branch (the wrong-branch-invisible proof), an
// org-a/fac-a2 row (facility isolation), and an org-b row (tenant
// isolation). Names are seeded OUT of alphabetical order — the read must
// order by name ASC (the exact Laravel `->orderBy('name')`).
function fiveDepartments() {
  return [
    fullDepartment(DEPT_ER, { name: 'Emergency', code: 'ER', branchId: 'br-a1', status: 'active' }),
    fullDepartment(DEPT_LAB, { name: 'Laboratory', code: 'LAB', branchId: null, status: 'inactive', parentDepartmentId: DEPT_CARD }),
    fullDepartment(DEPT_CARD, { name: 'Cardiology', code: 'CARD', branchId: 'br-a1', status: 'active' }),
    fullDepartment(DEPT_A1_OTHERBR, { name: 'Radiology', code: 'RAD', branchId: 'br-a1b', status: 'active' }),
    fullDepartment(DEPT_SUR, { tenantId: 'org-a', facilityId: 'fac-a2', branchId: 'br-a2', name: 'Surgery', code: 'SUR', status: 'active' }),
    fullDepartment(DEPT_B_ONC, { tenantId: 'org-b', facilityId: 'fac-b', branchId: 'br-b', name: 'Oncology', code: 'ONC', status: 'active' }),
  ];
}

function departmentsFixtureDeps({ departments } = {}) {
  return makeDeps({}, { seedDepartments: departments === undefined ? fiveDepartments() : departments });
}

// The exact DepartmentController::present() key set — nothing else ever
// leaves the handler.
const DEPARTMENT_ITEM_KEYS = ['branchId', 'code', 'facilityId', 'id', 'name', 'parentDepartmentId', 'status'];

// --- facilities:branches fixtures (Phase 34) ----------------------------

const BR_A1_CARD = 'br-a1-card';
const BR_A1_ER = 'br-a1-er';
const BR_A1_LAB = 'br-a1-lab';
const BR_A2_SUR = 'br-a2-sur';
const BR_B_ONC = 'br-b-onc';

function fullBranch(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1',
    name: 'Cardiology Clinic', code: 'CARD', status: 'active',
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Five branches across the scope matrix: three fac-a1 rows (one carrying an
// INACTIVE status — the NO-status-filter proof), a fac-a2 row (facility
// isolation), and an org-b row (tenant isolation). Names are seeded OUT of
// alphabetical order — the read must order by name ASC (the exact Laravel
// `->orderBy('name')`). Codes unique per (tenant, facility) —
// uq_branches_tenant_facility_code.
function branchMatrix() {
  return [
    fullBranch(BR_A1_ER, { name: 'Emergency Wing', code: 'ER', status: 'active' }),
    fullBranch(BR_A1_LAB, { name: 'Lab Services', code: 'LAB', status: 'inactive' }),
    fullBranch(BR_A1_CARD, { name: 'Cardiology Clinic', code: 'CARD', status: 'active' }),
    fullBranch(BR_A2_SUR, { tenantId: 'org-a', facilityId: 'fac-a2', name: 'Surgery Annex', code: 'SUR', status: 'active' }),
    fullBranch(BR_B_ONC, { tenantId: 'org-b', facilityId: 'fac-b', name: 'Oncology Unit', code: 'ONC', status: 'active' }),
  ];
}

function branchesFixtureDeps({ branches } = {}) {
  return makeDeps({}, { seedBranches: branches === undefined ? branchMatrix() : branches });
}

// The exact BranchController::present() key set — nothing else ever leaves
// the handler.
const BRANCH_ITEM_KEYS = ['code', 'facilityId', 'id', 'name', 'status'];

// --- facilities:settings fixtures (Phase 45) ----------------------------

// The stored setting row carries the internal fields the read must never
// present (tenantId/createdAt/updatedBy). key/value/version/updatedAt ARE
// contract-explicit (the exact FacilitySettingsController::index mapWithKeys
// entry). `value` is the DECODED jsonb payload (the 'array' cast — jsonb is
// already JSON and passes through unchanged); `updatedAt` is the
// toIso8601String timestamp ('+00:00' offset — Carbon's format) or null.
function fullSetting(key, overrides = {}) {
  return {
    key, tenantId: 'org-a', facilityId: 'fac-a1',
    value: { enabled: true },
    version: 1,
    updatedAt: '2026-03-02T10:00:00+00:00',
    createdAt: '2026-03-02T10:00:00+00:00', updatedBy: null,
    ...overrides,
  };
}

// Five settings across the scope matrix — facility_settings is
// **TENANT_FACILITY**: org-a/fac-a1 rows (one with a NULL updatedAt — the
// nullable timestamp proof; the versioned jsonb values — the value/version
// contract), an org-a/fac-a2 row (facility isolation), and an org-b row
// (tenant isolation). Keys are seeded OUT of alphabetical order — the read
// must order by key ASC (the exact Laravel `->orderBy('key')`). NO status
// column exists, NO soft-deletes — nothing is ever excluded. The unique
// (tenant_id, facility_id, key) index is respected in the fixture graph.
function fiveSettings() {
  return [
    fullSetting('appointment.bufferMinutes', { value: { minutes: 10 }, version: 2, updatedAt: '2026-03-10T08:30:00+00:00' }),
    fullSetting('billing.defaultCurrency', { value: 'NPR', version: 1, updatedAt: null }),
    fullSetting('clinic.name', { value: { displayName: 'Fac A1 Clinic' }, version: 3, updatedAt: '2026-04-01T12:00:00+00:00' }),
    fullSetting('reception.tokens', { tenantId: 'org-a', facilityId: 'fac-a2', value: { tokenPrefix: 'F2' }, version: 1, updatedAt: '2026-02-01T09:00:00+00:00' }),
    fullSetting('pharmacy.hours', { tenantId: 'org-b', facilityId: 'fac-b', value: { open: '09:00' }, version: 1, updatedAt: '2026-01-15T07:00:00+00:00' }),
  ];
}

function settingsFixtureDeps({ settings } = {}) {
  return makeDeps({}, { seedSettings: settings === undefined ? fiveSettings() : settings });
}

// The exact FacilitySettingsController::index mapWithKeys shape — the fac-a1
// read returns the keyed OBJECT (never an array) in key order; the fac-a2 +
// org-b rows are bound to OTHER facilities and never appear. `value` is the
// decoded jsonb payload, `version` the integer counter, `updatedAt` the
// toIso8601String ('+00:00') or null.
const facA1SettingsOrdered = {
  'appointment.bufferMinutes': { value: { minutes: 10 }, version: 2, updatedAt: '2026-03-10T08:30:00+00:00' },
  'billing.defaultCurrency': { value: 'NPR', version: 1, updatedAt: null },
  'clinic.name': { value: { displayName: 'Fac A1 Clinic' }, version: 3, updatedAt: '2026-04-01T12:00:00+00:00' },
};

// --- organizations:locations fixtures (Phase 35) ------------------------

const LOC_A1_STORE = 'loc-a1-store';
const LOC_A1_WAIT = 'loc-a1-wait';
const LOC_A1_NURSE = 'loc-a1-nurse';
const LOC_A2_PROC = 'loc-a2-proc';
const LOC_B_STORE = 'loc-b-store';

function fullLocation(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', branchId: 'br-a1',
    name: 'Central Store', code: 'STORE', type: 'store', status: 'active',
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Five locations across the scope matrix: org-a/fac-a1/br-a1 rows (one with
// a NULL branch — the org/facility-level row — and one carrying an INACTIVE
// status — the NO-status-filter proof), an org-a/fac-a2 row (facility
// isolation), and an org-b row (tenant isolation). Names are seeded OUT of
// alphabetical order — the read must order by name ASC (the exact Laravel
// `->orderBy('name')`). Codes unique per (tenant, facility) —
// uq_locations_tenant_facility_code.
function fiveLocations() {
  return [
    fullLocation(LOC_A1_WAIT, { name: 'Reception Waiting', code: 'WAIT', type: 'waiting_area', status: 'active' }),
    fullLocation(LOC_A1_NURSE, { name: 'Nursing Station', code: 'NURSE', type: 'nursing_station', status: 'inactive', branchId: null }),
    fullLocation(LOC_A1_STORE, { name: 'Central Store', code: 'STORE', type: 'store', status: 'active' }),
    fullLocation(LOC_A2_PROC, { tenantId: 'org-a', facilityId: 'fac-a2', branchId: 'br-a2', name: 'Procedure Suite', code: 'PROC', type: 'procedure_area', status: 'active' }),
    fullLocation(LOC_B_STORE, { tenantId: 'org-b', facilityId: 'fac-b', branchId: 'br-b', name: 'Storage B', code: 'STOB', type: 'store', status: 'active' }),
  ];
}

function locationsFixtureDeps({ locations } = {}) {
  return makeDeps({}, { seedLocations: locations === undefined ? fiveLocations() : locations });
}

// The exact LocationController::present() key set — nothing else ever leaves
// the handler.
const LOCATION_ITEM_KEYS = ['branchId', 'code', 'facilityId', 'id', 'name', 'status', 'type'];

// --- organizations:wards fixtures (Phase 36) ---------------------------

const WARD_A1_GEN = 'ward-a1';
const WARD_A1_ICU = 'ward-a2';
const WARD_A1_PED = 'ward-a3';
const WARD_A1_OTHERBR = 'ward-a4';
const WARD_A2_SUR = 'ward-a5';
const WARD_B_MAT = 'ward-b1';

function fullWard(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', branchId: 'br-a1',
    name: 'General Ward', code: 'GEN', wardType: 'general', status: 'active',
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Six wards across the scope matrix: org-a/fac-a1/br-a1 rows (one with a
// NULL branch — the org/facility-level row — and one carrying an INACTIVE
// status — the NO-status-filter proof), an org-a/fac-a1 row on a DIFFERENT
// fac-a1 branch (the wrong-branch-invisible proof), an org-a/fac-a2 row
// (facility isolation), and an org-b row (tenant isolation). Names are
// seeded OUT of alphabetical order — the read must order by name ASC (the
// exact Laravel `->orderBy('name')`). Codes unique per (tenant, facility)
// — uq_wards_tenant_facility_code.
function sixWards() {
  return [
    fullWard(WARD_A1_ICU, { name: 'Intensive Care', code: 'ICU', wardType: 'icu', status: 'active' }),
    fullWard(WARD_A1_PED, { name: 'Pediatric', code: 'PED', wardType: 'pediatric', branchId: null, status: 'inactive' }),
    fullWard(WARD_A1_GEN, { name: 'General Ward', code: 'GEN', wardType: 'general', status: 'active' }),
    fullWard(WARD_A1_OTHERBR, { name: 'Maternity Wing A', code: 'MAT1', wardType: 'maternity', branchId: 'br-a1b', status: 'active' }),
    fullWard(WARD_A2_SUR, { tenantId: 'org-a', facilityId: 'fac-a2', branchId: 'br-a2', name: 'Surgery', code: 'SUR', wardType: 'surgery', status: 'active' }),
    fullWard(WARD_B_MAT, { tenantId: 'org-b', facilityId: 'fac-b', branchId: 'br-b', name: 'Oncology Suite', code: 'ONC', wardType: 'other', status: 'active' }),
  ];
}

function wardsFixtureDeps({ wards } = {}) {
  return makeDeps({}, { seedWards: wards === undefined ? sixWards() : wards });
}

// The exact WardController::present() key set — nothing else ever leaves
// the handler.
const WARD_ITEM_KEYS = ['branchId', 'code', 'facilityId', 'id', 'name', 'status', 'wardType'];

const ROOM_A1_GEN = 'room-a1';
const ROOM_A1_PRIV = 'room-a2';
const ROOM_A1_SEMI = 'room-a3';
const ROOM_A1_OTHERBR = 'room-a4';
const ROOM_A2_ICU = 'room-a5';
const ROOM_B_STD = 'room-b1';

function fullRoom(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', branchId: 'br-a1', wardId: WARD_A1_GEN,
    name: 'General Room', code: 'R-GEN', roomType: 'general',
    dailyRateMinor: 1000, currency: 'NPR', status: 'active',
    ward: { id: WARD_A1_GEN, code: 'GEN', name: 'General Ward' },
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Six rooms across the scope matrix: org-a/fac-a1/br-a1 rows (one with a
// NULL branch — the org/facility-level row — and one carrying an INACTIVE
// status — the NO-status-filter proof), an org-a/fac-a1 row on a DIFFERENT
// fac-a1 branch (the wrong-branch-invisible proof), an org-a/fac-a2 row
// (facility isolation), and an org-b row (tenant isolation). Names are
// seeded OUT of alphabetical order — the read must order by name ASC (the
// exact Laravel `->orderBy('name')`). Codes unique per (tenant, facility)
// — uq_rooms_tenant_facility_code. Rooms reference wards via the composite
// tenant/facility/ward FK — each room's ward belongs to the same
// tenant/facility.
function sixRooms() {
  return [
    fullRoom(ROOM_A1_PRIV, { name: 'Private Suite', code: 'R-PRIV', roomType: 'private', dailyRateMinor: 5000, currency: 'NPR', status: 'active' }),
    fullRoom(ROOM_A1_SEMI, { name: 'Semi-Private', code: 'R-SEMI', roomType: 'semi_private', dailyRateMinor: 2500, currency: 'NPR', branchId: null, status: 'inactive' }),
    fullRoom(ROOM_A1_GEN, { name: 'General Room', code: 'R-GEN', roomType: 'general', dailyRateMinor: 1000, currency: 'NPR', status: 'active' }),
    fullRoom(ROOM_A1_OTHERBR, { name: 'Maternity Room', code: 'R-MAT', roomType: 'other', dailyRateMinor: 3000, currency: 'NPR', branchId: 'br-a1b', status: 'active' }),
    fullRoom(ROOM_A2_ICU, { tenantId: 'org-a', facilityId: 'fac-a2', branchId: 'br-a2', wardId: WARD_A2_SUR, name: 'ICU Bay', code: 'R-ICU', roomType: 'icu', dailyRateMinor: 8000, currency: 'NPR', ward: { id: WARD_A2_SUR, code: 'SUR', name: 'Surgery' }, status: 'active' }),
    fullRoom(ROOM_B_STD, { tenantId: 'org-b', facilityId: 'fac-b', branchId: 'br-b', wardId: WARD_B_MAT, name: 'Standard Ward Room', code: 'R-STD', roomType: 'general', dailyRateMinor: 500, currency: 'NPR', ward: { id: WARD_B_MAT, code: 'ONC', name: 'Oncology Suite' }, status: 'active' }),
  ];
}

function roomsFixtureDeps({ rooms } = {}) {
  return makeDeps({}, { seedRooms: rooms === undefined ? sixRooms() : rooms });
}

// The exact RoomController::index key set — nothing else ever leaves the
// handler.
const ROOM_ITEM_KEYS = ['branchId', 'code', 'currency', 'dailyRateMinor', 'facilityId', 'id', 'name', 'roomType', 'status', 'ward', 'wardId'];
const WARD_REF_KEYS = ['code', 'id', 'name'];

// The org-a read returns the EXACT 11-field present() items in name order.
const orgARoomsOrdered = [
  { id: ROOM_A1_GEN, facilityId: 'fac-a1', branchId: 'br-a1', wardId: WARD_A1_GEN, ward: { id: WARD_A1_GEN, code: 'GEN', name: 'General Ward' }, name: 'General Room', code: 'R-GEN', roomType: 'general', dailyRateMinor: 1000, currency: 'NPR', status: 'active' },
  { id: ROOM_A2_ICU, facilityId: 'fac-a2', branchId: 'br-a2', wardId: WARD_A2_SUR, ward: { id: WARD_A2_SUR, code: 'SUR', name: 'Surgery' }, name: 'ICU Bay', code: 'R-ICU', roomType: 'icu', dailyRateMinor: 8000, currency: 'NPR', status: 'active' },
  { id: ROOM_A1_OTHERBR, facilityId: 'fac-a1', branchId: 'br-a1b', wardId: WARD_A1_GEN, ward: { id: WARD_A1_GEN, code: 'GEN', name: 'General Ward' }, name: 'Maternity Room', code: 'R-MAT', roomType: 'other', dailyRateMinor: 3000, currency: 'NPR', status: 'active' },
  { id: ROOM_A1_PRIV, facilityId: 'fac-a1', branchId: 'br-a1', wardId: WARD_A1_GEN, ward: { id: WARD_A1_GEN, code: 'GEN', name: 'General Ward' }, name: 'Private Suite', code: 'R-PRIV', roomType: 'private', dailyRateMinor: 5000, currency: 'NPR', status: 'active' },
  { id: ROOM_A1_SEMI, facilityId: 'fac-a1', branchId: null, wardId: WARD_A1_GEN, ward: { id: WARD_A1_GEN, code: 'GEN', name: 'General Ward' }, name: 'Semi-Private', code: 'R-SEMI', roomType: 'semi_private', dailyRateMinor: 2500, currency: 'NPR', status: 'inactive' },
];

const BED_A1_01 = 'bed-a1';
const BED_A1_02 = 'bed-a2';
const BED_A1_03 = 'bed-a3';
const BED_A1_OTHERBR = 'bed-a4';
const BED_A2_01 = 'bed-a5';
const BED_B_01 = 'bed-b1';

function fullBed(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', branchId: 'br-a1', roomId: ROOM_A1_GEN,
    bedCode: 'B-01', status: 'available', lockVersion: 0,
    room: { id: ROOM_A1_GEN, code: 'R-GEN', name: 'General Room' },
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Six beds across the scope matrix: org-a/fac-a1/br-a1 rows (one with a
// NULL branch — the org/facility-level row — and one carrying an
// OUT_OF_SERVICE status — the NO-status-filter + never-soft-deleted proof),
// an org-a/fac-a1 row on a DIFFERENT fac-a1 branch (the
// wrong-branch-invisible proof), an org-a/fac-a2 row (facility isolation),
// and an org-b row (tenant isolation). bed_codes are seeded OUT of
// lexicographic order — the read must order by bed_code ASC (the exact
// Laravel `->orderBy('bed_code')`). Codes unique per (tenant, room) —
// uq_beds_tenant_room_code. Beds reference rooms via the composite
// tenant/facility/room FK — each bed's room belongs to the same
// tenant/facility.
function sixBeds() {
  return [
    fullBed(BED_A1_02, { bedCode: 'B-02', status: 'occupied', lockVersion: 2 }),
    fullBed(BED_A1_03, { bedCode: 'B-03', branchId: null, status: 'out_of_service', lockVersion: 5 }),
    fullBed(BED_A1_01, { bedCode: 'B-01', status: 'available', lockVersion: 0 }),
    fullBed(BED_A1_OTHERBR, { bedCode: 'B-04', branchId: 'br-a1b', status: 'reserved', lockVersion: 1 }),
    fullBed(BED_A2_01, { tenantId: 'org-a', facilityId: 'fac-a2', branchId: 'br-a2', roomId: ROOM_A2_ICU, bedCode: 'I-01', status: 'cleaning', lockVersion: 3, room: { id: ROOM_A2_ICU, code: 'R-ICU', name: 'ICU Bay' } }),
    fullBed(BED_B_01, { tenantId: 'org-b', facilityId: 'fac-b', branchId: 'br-b', roomId: ROOM_B_STD, bedCode: 'S-01', status: 'available', lockVersion: 0, room: { id: ROOM_B_STD, code: 'R-STD', name: 'Standard Ward Room' } }),
  ];
}

function bedsFixtureDeps({ beds } = {}) {
  return makeDeps({}, { seedBeds: beds === undefined ? sixBeds() : beds });
}

// The exact BedController::index key set — nothing else ever leaves the
// handler.
const BED_ITEM_KEYS = ['bedCode', 'branchId', 'facilityId', 'id', 'lockVersion', 'room', 'roomId', 'status'];
const ROOM_REF_KEYS = ['code', 'id', 'name'];

// The org-a read returns the EXACT 8-field present() items in bed_code
// order.
const orgABedsOrdered = [
  { id: BED_A1_01, facilityId: 'fac-a1', branchId: 'br-a1', roomId: ROOM_A1_GEN, room: { id: ROOM_A1_GEN, code: 'R-GEN', name: 'General Room' }, bedCode: 'B-01', status: 'available', lockVersion: 0 },
  { id: BED_A1_02, facilityId: 'fac-a1', branchId: 'br-a1', roomId: ROOM_A1_GEN, room: { id: ROOM_A1_GEN, code: 'R-GEN', name: 'General Room' }, bedCode: 'B-02', status: 'occupied', lockVersion: 2 },
  { id: BED_A1_03, facilityId: 'fac-a1', branchId: null, roomId: ROOM_A1_GEN, room: { id: ROOM_A1_GEN, code: 'R-GEN', name: 'General Room' }, bedCode: 'B-03', status: 'out_of_service', lockVersion: 5 },
  { id: BED_A1_OTHERBR, facilityId: 'fac-a1', branchId: 'br-a1b', roomId: ROOM_A1_GEN, room: { id: ROOM_A1_GEN, code: 'R-GEN', name: 'General Room' }, bedCode: 'B-04', status: 'reserved', lockVersion: 1 },
  { id: BED_A2_01, facilityId: 'fac-a2', branchId: 'br-a2', roomId: ROOM_A2_ICU, room: { id: ROOM_A2_ICU, code: 'R-ICU', name: 'ICU Bay' }, bedCode: 'I-01', status: 'cleaning', lockVersion: 3 },
];

const STAFF_A1_DR = 'staff-a1';
const STAFF_A1_NR = 'staff-a2';
const STAFF_A1_LB = 'staff-a3';
const STAFF_A1_GD = 'staff-a4';
const STAFF_A2_IC = 'staff-a5';
const STAFF_B_DR = 'staff-b1';

function fullStaff(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', departmentId: DEPT_CARD,
    employeeCode: 'EMP-001', fullName: 'Aarav Sharma', designation: 'Cardiologist',
    status: 'active', userId: null, hireDate: '2024-01-15',
    department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' },
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null,
    ...overrides,
  };
}

// Six staff across the scope matrix: org-a/fac-a1 rows (one DEPARTED — the
// NO-status-filter + never-soft-deleted proof — and one with a NULL user/
// designation/hire-date and a NULL department ref — the nullable + ref-null
// proofs), an org-a/fac-a2 row (facility isolation), and an org-b row
// (tenant isolation). full_names are seeded OUT of alphabetical order — the
// read must order by full_name ASC (the exact Laravel `->orderBy('full_name')`).
// employee_codes unique per tenant — uq_staff_tenant_employee_code; at most
// one NON-departed record per user per tenant — uq_staff_tenant_active_user
// (the departed row may share a user).
function sixStaff() {
  return [
    fullStaff(STAFF_A1_NR, { employeeCode: 'EMP-002', fullName: 'Bina Gurung', designation: 'Nurse', status: 'active', userId: 'u-user-2', hireDate: '2023-06-01' }),
    fullStaff(STAFF_A1_LB, { employeeCode: 'EMP-003', fullName: 'Chandra Rai', designation: 'Lab Tech', status: 'on_leave', userId: null, hireDate: null }),
    fullStaff(STAFF_A1_DR, { employeeCode: 'EMP-001', fullName: 'Aarav Sharma', designation: 'Cardiologist', status: 'active', userId: 'u-user-1', hireDate: '2024-01-15' }),
    fullStaff(STAFF_A1_GD, { employeeCode: 'EMP-004', fullName: 'Dawa Sherpa', designation: null, status: 'departed', userId: 'u-user-1', hireDate: '2022-03-10', department: null }),
    fullStaff(STAFF_A2_IC, { tenantId: 'org-a', facilityId: 'fac-a2', departmentId: DEPT_SUR, employeeCode: 'EMP-101', fullName: 'Erika Tamang', designation: 'ICU Specialist', status: 'active', userId: 'u-user-3', hireDate: '2025-02-20', department: { id: DEPT_SUR, code: 'SUR', name: 'Surgery' } }),
    fullStaff(STAFF_B_DR, { tenantId: 'org-b', facilityId: 'fac-b', departmentId: DEPT_B_ONC, employeeCode: 'EMP-501', fullName: 'Femi Joshi', designation: 'Oncologist', status: 'active', userId: 'u-user-4', hireDate: '2024-09-01', department: { id: DEPT_B_ONC, code: 'ONC', name: 'Oncology' } }),
  ];
}

function staffFixtureDeps({ staff } = {}) {
  return makeDeps({}, { seedStaff: staff === undefined ? sixStaff() : staff });
}

// The exact StaffController::index key set — nothing else ever leaves the
// handler.
const STAFF_ITEM_KEYS = ['department', 'departmentId', 'designation', 'employeeCode', 'facilityId', 'fullName', 'hireDate', 'id', 'status', 'userId'];
const DEPT_REF_KEYS = ['code', 'id', 'name'];

// The org-a read returns the EXACT 10-field present() items in full_name
// order.
const orgAStaffOrdered = [
  { id: STAFF_A1_DR, facilityId: 'fac-a1', departmentId: DEPT_CARD, department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' }, employeeCode: 'EMP-001', fullName: 'Aarav Sharma', designation: 'Cardiologist', status: 'active', userId: 'u-user-1', hireDate: '2024-01-15' },
  { id: STAFF_A1_NR, facilityId: 'fac-a1', departmentId: DEPT_CARD, department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' }, employeeCode: 'EMP-002', fullName: 'Bina Gurung', designation: 'Nurse', status: 'active', userId: 'u-user-2', hireDate: '2023-06-01' },
  { id: STAFF_A1_LB, facilityId: 'fac-a1', departmentId: DEPT_CARD, department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' }, employeeCode: 'EMP-003', fullName: 'Chandra Rai', designation: 'Lab Tech', status: 'on_leave', userId: null, hireDate: null },
  { id: STAFF_A1_GD, facilityId: 'fac-a1', departmentId: DEPT_CARD, department: null, employeeCode: 'EMP-004', fullName: 'Dawa Sherpa', designation: null, status: 'departed', userId: 'u-user-1', hireDate: '2022-03-10' },
  { id: STAFF_A2_IC, facilityId: 'fac-a2', departmentId: DEPT_SUR, department: { id: DEPT_SUR, code: 'SUR', name: 'Surgery' }, employeeCode: 'EMP-101', fullName: 'Erika Tamang', designation: 'ICU Specialist', status: 'active', userId: 'u-user-3', hireDate: '2025-02-20' },
];

const SVC_A1_OPD = 'svc-a1';
const SVC_A1_PROC = 'svc-a2';
const SVC_A1_LAB = 'svc-a3';
const SVC_A1_DEL = 'svc-a4';
const SVC_A2_SUR = 'svc-a5';
const SVC_B_ONC = 'svc-b1';

function fullService(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', departmentId: DEPT_CARD,
    name: 'OPD Consultation', code: 'SVC-OPD', serviceType: 'opd_consultation',
    status: 'active', defaultDurationMinutes: 15, defaultChargeMinor: 50000, currency: 'NPR',
    department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' },
    createdAt: '2026-01-01T00:00:00Z', updatedAt: null, deletedAt: null,
    ...overrides,
  };
}

// Seven services across the scope matrix: org-a/fac-a1 rows (one INACTIVE —
// the NO-status-filter proof; one department-less — the nullable
// departmentId + null-ref proof; one SOFT-DELETED — the SoftDeletes model
// scope proof, excluded from the read), an org-a/fac-a2 row (facility
// isolation), and an org-b row (tenant isolation). Names are seeded OUT of
// alphabetical order — the read must order by name ASC (the exact Laravel
// `->orderBy('name')`). Codes unique per (tenant, facility) among live rows
// — uq_services_tenant_facility_code (partial, where deleted_at is null);
// the composite FK (tenant, facility, department_id) → departments allows
// NULL department_id.
function sevenServices() {
  return [
    fullService(SVC_A1_PROC, { name: 'Procedure', code: 'SVC-PROC', serviceType: 'procedure', defaultDurationMinutes: 60, defaultChargeMinor: 250000, currency: 'NPR', status: 'active' }),
    fullService(SVC_A1_LAB, { name: 'Lab Investigation', code: 'SVC-LAB', serviceType: 'investigation', defaultDurationMinutes: null, defaultChargeMinor: null, currency: null, departmentId: null, department: null, status: 'inactive' }),
    fullService(SVC_A1_OPD, { name: 'OPD Consultation', code: 'SVC-OPD', serviceType: 'opd_consultation', defaultDurationMinutes: 15, defaultChargeMinor: 50000, currency: 'NPR', status: 'active' }),
    fullService(SVC_A1_DEL, { name: 'Deleted Follow-up', code: 'SVC-FU', serviceType: 'follow_up', defaultDurationMinutes: 10, defaultChargeMinor: 10000, currency: 'NPR', status: 'active', deletedAt: '2026-02-01T00:00:00Z' }),
    fullService(SVC_A2_SUR, { tenantId: 'org-a', facilityId: 'fac-a2', departmentId: DEPT_SUR, name: 'Surgery', code: 'SVC-SUR', serviceType: 'procedure', defaultDurationMinutes: 120, defaultChargeMinor: 800000, currency: 'NPR', department: { id: DEPT_SUR, code: 'SUR', name: 'Surgery' }, status: 'active' }),
    fullService(SVC_B_ONC, { tenantId: 'org-b', facilityId: 'fac-b', departmentId: DEPT_B_ONC, name: 'Oncology Consult', code: 'SVC-ONC', serviceType: 'opd_consultation', defaultDurationMinutes: 30, defaultChargeMinor: 150000, currency: 'NPR', department: { id: DEPT_B_ONC, code: 'ONC', name: 'Oncology' }, status: 'active' }),
  ];
}

function servicesFixtureDeps({ services } = {}) {
  return makeDeps({}, { seedServices: services === undefined ? sevenServices() : services });
}

// The exact ServiceController::index key set — nothing else ever leaves the
// handler.
const SERVICE_ITEM_KEYS = ['code', 'currency', 'defaultChargeMinor', 'defaultDurationMinutes', 'department', 'departmentId', 'facilityId', 'id', 'name', 'serviceType', 'status'];
const SERVICE_DEPT_REF_KEYS = ['code', 'id', 'name'];

// The org-a read returns the EXACT 10-field present() items in name order
// (the soft-deleted Follow-up row is EXCLUDED by the SoftDeletes scope).
const orgAServicesOrdered = [
  { id: SVC_A1_LAB, facilityId: 'fac-a1', departmentId: null, department: null, name: 'Lab Investigation', code: 'SVC-LAB', serviceType: 'investigation', status: 'inactive', defaultDurationMinutes: null, defaultChargeMinor: null, currency: null },
  { id: SVC_A1_OPD, facilityId: 'fac-a1', departmentId: DEPT_CARD, department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' }, name: 'OPD Consultation', code: 'SVC-OPD', serviceType: 'opd_consultation', status: 'active', defaultDurationMinutes: 15, defaultChargeMinor: 50000, currency: 'NPR' },
  { id: SVC_A1_PROC, facilityId: 'fac-a1', departmentId: DEPT_CARD, department: { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' }, name: 'Procedure', code: 'SVC-PROC', serviceType: 'procedure', status: 'active', defaultDurationMinutes: 60, defaultChargeMinor: 250000, currency: 'NPR' },
  { id: SVC_A2_SUR, facilityId: 'fac-a2', departmentId: DEPT_SUR, department: { id: DEPT_SUR, code: 'SUR', name: 'Surgery' }, name: 'Surgery', code: 'SVC-SUR', serviceType: 'procedure', status: 'active', defaultDurationMinutes: 120, defaultChargeMinor: 800000, currency: 'NPR' },
];

// Phase 41 — organizations:payers --------------------------------------

const PYR_A_GOV = 'aaaaaaaa-0000-4000-8000-00000000f031';
const PYR_A_TPA = 'aaaaaaaa-0000-4000-8000-00000000f032';
const PYR_A_OTHER = 'aaaaaaaa-0000-4000-8000-00000000f033';
const PYR_A_PRIV = 'aaaaaaaa-0000-4000-8000-00000000f034';
const PYR_B_TPA = 'aaaaaaaa-0000-4000-8000-00000000f035';

// The stored payer row carries the internal fields the read must never
// present (tenantId/createdAt/createdBy/updatedBy). id/name/code/
// payerType/status ARE contract-explicit (the exact Laravel present() map).
function fullPayer(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', name: 'Payer', code: 'PAY',
    payerType: 'private', status: 'active',
    createdAt: '2026-03-02T10:00:00Z', createdBy: null, updatedBy: null,
    ...overrides,
  };
}

// Six payers across the scope matrix — payers is **TENANT_ONLY** (NO
// facility_id column, so there is NO facility dimension at all): org-a rows
// (one INACTIVE — the NO-status-filter proof; all four payer_type values
// covered), and an org-b row (tenant isolation). Names are seeded OUT of
// alphabetical order — the read must order by name ASC (the exact Laravel
// `->orderBy('name')`). Codes unique per tenant — uq_payers_tenant_code;
// Payer has NO SoftDeletes — nothing is ever excluded.
function sixPayers() {
  return [
    fullPayer(PYR_A_TPA, { name: 'Star TPA', code: 'STAR', payerType: 'tpa', status: 'active' }),
    fullPayer(PYR_A_OTHER, { name: 'Walk-in Self Pay', code: 'SELF', payerType: 'other', status: 'inactive' }),
    fullPayer(PYR_A_GOV, { name: 'Government Health Fund', code: 'GHF', payerType: 'government', status: 'active' }),
    fullPayer(PYR_A_PRIV, { name: 'National Insurance', code: 'NIC', payerType: 'private', status: 'active' }),
    fullPayer(PYR_B_TPA, { tenantId: 'org-b', name: 'Sagarmatha TPA', code: 'SAG', payerType: 'tpa', status: 'active' }),
  ];
}

function payersFixtureDeps({ payers } = {}) {
  return makeDeps({}, { seedPayers: payers === undefined ? sixPayers() : payers });
}

// The exact PayerController::index key set — nothing else ever leaves the
// handler.
const PAYER_ITEM_KEYS = ['code', 'id', 'name', 'payerType', 'status'];

// The org-a read returns the EXACT 5-field present() items in name order
// (the INACTIVE Walk-in Self Pay row IS included — NO status filter; the
// org-b row is NOT).
const orgAPayersOrdered = [
  { id: PYR_A_GOV, name: 'Government Health Fund', code: 'GHF', payerType: 'government', status: 'active' },
  { id: PYR_A_PRIV, name: 'National Insurance', code: 'NIC', payerType: 'private', status: 'active' },
  { id: PYR_A_TPA, name: 'Star TPA', code: 'STAR', payerType: 'tpa', status: 'active' },
  { id: PYR_A_OTHER, name: 'Walk-in Self Pay', code: 'SELF', payerType: 'other', status: 'inactive' },
];

// Phase 42 — organizations:medications --------------------------------

const MED_A1_AMOX = 'aaaaaaaa-0000-4000-8000-00000000f041';
const MED_A1_IBU = 'aaaaaaaa-0000-4000-8000-00000000f042';
const MED_A1_MET = 'aaaaaaaa-0000-4000-8000-00000000f043';
const MED_A1_DEL = 'aaaaaaaa-0000-4000-8000-00000000f044';
const MED_A2_PARA = 'aaaaaaaa-0000-4000-8000-00000000f045';
const MED_B_INSULIN = 'aaaaaaaa-0000-4000-8000-00000000f046';

// The stored medication row carries the internal fields the read must
// never present (tenantId/createdAt/createdBy/updatedBy/lockVersion).
// id/facilityId/code/genericName/brandName/strength/form/unit/priceMinor/
// currency/isControlled/status ARE contract-explicit (the exact Laravel
// present() map — lock_version is NOT presented for medications).
function fullMedication(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', code: 'MED',
    genericName: 'Medication', brandName: null, strength: '500mg',
    form: 'tablet', unit: 'tab', priceMinor: 10000, currency: 'NPR',
    isControlled: false, status: 'active', lockVersion: 0,
    createdAt: '2026-03-02T10:00:00Z', createdBy: null, updatedBy: null,
    deletedAt: null,
    ...overrides,
  };
}

// Six medications across the scope matrix — medications is
// **TENANT_FACILITY** (NO branch_id column, so there is NO branch
// dimension): org-a/fac-a1 rows (one INACTIVE — the NO-status-filter
// proof; one with a NULL brandName — the nullable proof; one with
// isControlled + a distinctive strength/form/unit — the formulary field
// proof; one SOFT-DELETED — the SoftDeletes model scope proof, excluded
// from the read), an org-a/fac-a2 row (facility isolation), and an org-b
// row (tenant isolation). Generic names are seeded OUT of alphabetical
// order — the read must order by generic_name ASC (the exact Laravel
// `->orderBy('generic_name')`). Codes unique per (tenant, facility) among
// live rows — uq_medications_tenant_facility_code (partial, where
// deleted_at is null); the composite FK (tenant, facility) → facilities.
function sixMedications() {
  return [
    fullMedication(MED_A1_IBU, { code: 'MED-IBU', genericName: 'Ibuprofen', brandName: 'Brufen', strength: '400mg', form: 'tablet', unit: 'tab', priceMinor: 25000, currency: 'NPR', status: 'inactive' }),
    fullMedication(MED_A1_MET, { code: 'MED-MET', genericName: 'Metformin', brandName: null, strength: '500mg', form: 'tablet', unit: 'tab', priceMinor: 15000, currency: 'NPR', isControlled: false, status: 'active' }),
    fullMedication(MED_A1_AMOX, { code: 'MED-AMOX', genericName: 'Amoxicillin', brandName: 'Amoxil', strength: '250mg', form: 'capsule', unit: 'cap', priceMinor: 30000, currency: 'NPR', status: 'active' }),
    fullMedication(MED_A1_DEL, { code: 'MED-DEL', genericName: 'Delisted Syrup', brandName: null, strength: '120ml', form: 'syrup', unit: 'bottle', priceMinor: 90000, currency: 'NPR', status: 'active', deletedAt: '2026-02-01T00:00:00Z' }),
    fullMedication(MED_A2_PARA, { tenantId: 'org-a', facilityId: 'fac-a2', code: 'MED-PARA', genericName: 'Paracetamol', brandName: 'Calpol', strength: '500mg', form: 'tablet', unit: 'tab', priceMinor: 10000, currency: 'NPR', status: 'active' }),
    fullMedication(MED_B_INSULIN, { tenantId: 'org-b', facilityId: 'fac-b', code: 'MED-INS', genericName: 'Insulin', brandName: 'Humulin', strength: '100IU', form: 'injection', unit: 'vial', priceMinor: 120000, currency: 'NPR', isControlled: true, status: 'active' }),
  ];
}

function medicationsFixtureDeps({ medications } = {}) {
  return makeDeps({}, { seedMedications: medications === undefined ? sixMedications() : medications });
}

// The exact MedicationController::index key set — nothing else ever leaves
// the handler.
const MEDICATION_ITEM_KEYS = ['brandName', 'code', 'currency', 'facilityId', 'form', 'genericName', 'id', 'isControlled', 'priceMinor', 'status', 'strength', 'unit'];

// The org-a read returns the EXACT 11-field present() items in generic_name
// order (the soft-deleted Delisted Syrup row is EXCLUDED by the SoftDeletes
// scope).
const orgAMedicationsOrdered = [
  { id: MED_A1_AMOX, facilityId: 'fac-a1', code: 'MED-AMOX', genericName: 'Amoxicillin', brandName: 'Amoxil', strength: '250mg', form: 'capsule', unit: 'cap', priceMinor: 30000, currency: 'NPR', isControlled: false, status: 'active' },
  { id: MED_A1_IBU, facilityId: 'fac-a1', code: 'MED-IBU', genericName: 'Ibuprofen', brandName: 'Brufen', strength: '400mg', form: 'tablet', unit: 'tab', priceMinor: 25000, currency: 'NPR', isControlled: false, status: 'inactive' },
  { id: MED_A1_MET, facilityId: 'fac-a1', code: 'MED-MET', genericName: 'Metformin', brandName: null, strength: '500mg', form: 'tablet', unit: 'tab', priceMinor: 15000, currency: 'NPR', isControlled: false, status: 'active' },
  { id: MED_A2_PARA, facilityId: 'fac-a2', code: 'MED-PARA', genericName: 'Paracetamol', brandName: 'Calpol', strength: '500mg', form: 'tablet', unit: 'tab', priceMinor: 10000, currency: 'NPR', isControlled: false, status: 'active' },
];

// Phase 43 — organizations:schedule-templates --------------------------

const TEM_A1_MON = 'aaaaaaaa-0000-4000-8000-00000000f051';
const TEM_A1_TUE = 'aaaaaaaa-0000-4000-8000-00000000f052';
const TEM_A1_SUN = 'aaaaaaaa-0000-4000-8000-00000000f053';
const TEM_A1_DEL = 'aaaaaaaa-0000-4000-8000-00000000f054';
const TEM_A1_NOSVC = 'aaaaaaaa-0000-4000-8000-00000000f055';
const TEM_A2_THU = 'aaaaaaaa-0000-4000-8000-00000000f056';
const TEM_B_SUN = 'aaaaaaaa-0000-4000-8000-00000000f057';

// The stored template row carries the internal fields the read must never
// present (tenantId/createdAt/createdBy/updatedBy). id/facilityId/staffId/
// staff/serviceId/dayOfWeek/startsAt/endsAt/slotMinutes/capacity/
// validFrom/validTo/status ARE contract-explicit (the exact Laravel
// presentTemplate map).
function fullScheduleTemplate(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', staffId: STAFF_A1_DR,
    staff: { id: STAFF_A1_DR, fullName: 'Aarav Sharma', designation: 'Cardiologist' },
    serviceId: 'svc-a1', dayOfWeek: 1,
    startsAt: '09:00', endsAt: '12:00',
    slotMinutes: 30, capacity: 2,
    validFrom: '2026-01-01', validTo: '2026-12-31',
    status: 'active',
    createdAt: '2026-03-02T10:00:00Z', createdBy: null, updatedBy: null,
    deletedAt: null,
    ...overrides,
  };
}

// Seven templates across the scope matrix — schedule_templates is
// **TENANT_FACILITY** (NO branch_id column, so there is NO branch
// dimension): org-a/fac-a1 rows (one INACTIVE — the NO-status-filter
// proof; one with a NULL serviceId — the nullable proof; one
// SOFT-DELETED — the SoftDeletes model scope proof, excluded from the
// read), an org-a/fac-a2 row (facility isolation), and an org-b row
// (tenant isolation). day_of_week values seeded OUT of ascending order
// — the read must order by day_of_week ASC (the exact Laravel
// `->orderBy('day_of_week')`; ISO 8601 — 0 Sun .. 6 Sat). The composite
// FKs (tenant, facility, staff_id) → staff (RESTRICT — staff has NO
// SoftDeletes, so the eager ref always resolves in a consistent DB — the
// Laravel `?: null` is unreachable in practice) and (tenant, facility,
// service_id) → services (service_id NULL-allowed).
function sevenScheduleTemplates() {
  return [
    fullScheduleTemplate(TEM_A1_MON, { dayOfWeek: 1, startsAt: '09:00', endsAt: '12:00', slotMinutes: 30, capacity: 2, validFrom: '2026-01-01', validTo: '2026-12-31', status: 'active' }),
    fullScheduleTemplate(TEM_A1_TUE, { dayOfWeek: 2, startsAt: '13:00', endsAt: '16:00', slotMinutes: 45, capacity: 1, validFrom: '2026-01-01', validTo: null, status: 'inactive' }),
    fullScheduleTemplate(TEM_A1_SUN, { staffId: STAFF_A1_NR, staff: { id: STAFF_A1_NR, fullName: 'Bina Gurung', designation: 'Nurse' }, dayOfWeek: 0, startsAt: '08:00', endsAt: '10:00', slotMinutes: 20, capacity: 3, validFrom: '2026-01-01', validTo: '2026-06-30', status: 'active' }),
    fullScheduleTemplate(TEM_A1_DEL, { dayOfWeek: 3, startsAt: '10:00', endsAt: '11:00', slotMinutes: 30, capacity: 1, validFrom: '2026-01-01', validTo: null, status: 'active', deletedAt: '2026-02-01T00:00:00Z' }),
    fullScheduleTemplate(TEM_A1_NOSVC, { staffId: STAFF_A1_GD, staff: { id: STAFF_A1_GD, fullName: 'Dawa Sherpa', designation: null }, serviceId: null, dayOfWeek: 4, startsAt: '09:30', endsAt: '11:30', slotMinutes: 30, capacity: 1, validFrom: '2026-02-01', validTo: null, status: 'active' }),
    fullScheduleTemplate(TEM_A2_THU, { tenantId: 'org-a', facilityId: 'fac-a2', staffId: STAFF_A2_IC, staff: { id: STAFF_A2_IC, fullName: 'Erika Tamang', designation: 'ICU Specialist' }, dayOfWeek: 4, startsAt: '09:00', endsAt: '13:00', slotMinutes: 60, capacity: 4, validFrom: '2026-01-01', validTo: '2026-12-31', status: 'active' }),
    fullScheduleTemplate(TEM_B_SUN, { tenantId: 'org-b', facilityId: 'fac-b', staffId: STAFF_B_DR, staff: { id: STAFF_B_DR, fullName: 'Femi Joshi', designation: 'Oncologist' }, dayOfWeek: 0, startsAt: '09:00', endsAt: '12:00', slotMinutes: 30, capacity: 2, validFrom: '2026-01-01', validTo: null, status: 'active' }),
  ];
}

function scheduleTemplatesFixtureDeps({ templates } = {}) {
  return makeDeps({}, { seedScheduleTemplates: templates === undefined ? sevenScheduleTemplates() : templates });
}

// The exact ScheduleController::presentTemplate key set — nothing else ever
// leaves the handler.
const SCHEDULE_TEMPLATE_ITEM_KEYS = ['capacity', 'dayOfWeek', 'endsAt', 'facilityId', 'id', 'serviceId', 'slotMinutes', 'staff', 'staffId', 'startsAt', 'status', 'validFrom', 'validTo'];
const SCHEDULE_TEMPLATE_STAFF_REF_KEYS = ['designation', 'fullName', 'id'];

// The org-a read returns the EXACT 13-field present() items in day_of_week
// order (the soft-deleted row is EXCLUDED by the SoftDeletes scope; the
// fac-a2 row IS visible to the org-level caller; the org-b row is NOT).
const orgAScheduleTemplatesOrdered = [
  { id: TEM_A1_SUN, facilityId: 'fac-a1', staffId: STAFF_A1_NR, staff: { id: STAFF_A1_NR, fullName: 'Bina Gurung', designation: 'Nurse' }, serviceId: 'svc-a1', dayOfWeek: 0, startsAt: '08:00', endsAt: '10:00', slotMinutes: 20, capacity: 3, validFrom: '2026-01-01', validTo: '2026-06-30', status: 'active' },
  { id: TEM_A1_MON, facilityId: 'fac-a1', staffId: STAFF_A1_DR, staff: { id: STAFF_A1_DR, fullName: 'Aarav Sharma', designation: 'Cardiologist' }, serviceId: 'svc-a1', dayOfWeek: 1, startsAt: '09:00', endsAt: '12:00', slotMinutes: 30, capacity: 2, validFrom: '2026-01-01', validTo: '2026-12-31', status: 'active' },
  { id: TEM_A1_TUE, facilityId: 'fac-a1', staffId: STAFF_A1_DR, staff: { id: STAFF_A1_DR, fullName: 'Aarav Sharma', designation: 'Cardiologist' }, serviceId: 'svc-a1', dayOfWeek: 2, startsAt: '13:00', endsAt: '16:00', slotMinutes: 45, capacity: 1, validFrom: '2026-01-01', validTo: null, status: 'inactive' },
  { id: TEM_A1_NOSVC, facilityId: 'fac-a1', staffId: STAFF_A1_GD, staff: { id: STAFF_A1_GD, fullName: 'Dawa Sherpa', designation: null }, serviceId: null, dayOfWeek: 4, startsAt: '09:30', endsAt: '11:30', slotMinutes: 30, capacity: 1, validFrom: '2026-02-01', validTo: null, status: 'active' },
  { id: TEM_A2_THU, facilityId: 'fac-a2', staffId: STAFF_A2_IC, staff: { id: STAFF_A2_IC, fullName: 'Erika Tamang', designation: 'ICU Specialist' }, serviceId: 'svc-a1', dayOfWeek: 4, startsAt: '09:00', endsAt: '13:00', slotMinutes: 60, capacity: 4, validFrom: '2026-01-01', validTo: '2026-12-31', status: 'active' },
];

// Phase 44 — organizations:schedule-exceptions --------------------------

const EXC_A1_LV = 'aaaaaaaa-0000-4000-8000-00000000f061';
const EXC_A1_HOL_CXL = 'aaaaaaaa-0000-4000-8000-00000000f062';
const EXC_A1_BLK = 'aaaaaaaa-0000-4000-8000-00000000f063';
const EXC_A2_LV = 'aaaaaaaa-0000-4000-8000-00000000f064';
const EXC_B_HOL = 'aaaaaaaa-0000-4000-8000-00000000f065';

// The stored exception row carries the internal fields the read must never
// present (tenantId/createdAt/createdBy/updatedBy). id/facilityId/staffId/
// exceptionDate/reason/status ARE contract-explicit (the exact Laravel
// presentException map — NOTE: unlike the templates read, the staff
// reference is NOT presented; the eager `with('staff:id,full_name')` is a
// query-level detail only).
function fullScheduleException(id, overrides = {}) {
  return {
    id, tenantId: 'org-a', facilityId: 'fac-a1', staffId: STAFF_A1_DR,
    exceptionDate: '2026-03-05',
    reason: 'leave', status: 'active',
    createdAt: '2026-03-02T10:00:00Z', createdBy: null, updatedBy: null,
    ...overrides,
  };
}

// Five exceptions across the scope matrix — schedule_exceptions is
// **TENANT_FACILITY** (NO branch_id column, so there is NO branch
// dimension): org-a/fac-a1 rows (one CANCELLED — the NO-status-filter
// proof; leave/holiday/block reasons — the CHECK-constrained values), an
// org-a/fac-a2 row (facility isolation), and an org-b row (tenant
// isolation). exception_date values seeded OUT of descending order — the
// read must order by exception_date DESC (the exact Laravel
// `->orderByDesc('exception_date')`). The composite FK (tenant, facility,
// staff_id) → staff (RESTRICT — staff has NO SoftDeletes). NOT
// soft-deletable — no deleted_at (the model has no SoftDeletes trait).
// Each (tenant_id, staff_id, exception_date) triple is unique (the
// uq_schedule_exceptions_tenant_staff_date index).
function fiveScheduleExceptions() {
  return [
    fullScheduleException(EXC_A1_LV, { staffId: STAFF_A1_DR, exceptionDate: '2026-03-05', reason: 'leave', status: 'active' }),
    fullScheduleException(EXC_A1_HOL_CXL, { staffId: STAFF_A1_NR, exceptionDate: '2026-02-14', reason: 'holiday', status: 'cancelled' }),
    fullScheduleException(EXC_A1_BLK, { staffId: STAFF_A1_DR, exceptionDate: '2026-04-01', reason: 'block', status: 'active' }),
    fullScheduleException(EXC_A2_LV, { tenantId: 'org-a', facilityId: 'fac-a2', staffId: STAFF_A2_IC, exceptionDate: '2026-02-20', reason: 'leave', status: 'active' }),
    fullScheduleException(EXC_B_HOL, { tenantId: 'org-b', facilityId: 'fac-b', staffId: STAFF_B_DR, exceptionDate: '2026-03-01', reason: 'holiday', status: 'active' }),
  ];
}

function scheduleExceptionsFixtureDeps({ exceptions } = {}) {
  return makeDeps({}, { seedScheduleExceptions: exceptions === undefined ? fiveScheduleExceptions() : exceptions });
}

// The exact ScheduleController::presentException key set — nothing else ever
// leaves the handler.
const SCHEDULE_EXCEPTION_ITEM_KEYS = ['exceptionDate', 'facilityId', 'id', 'reason', 'staffId', 'status'];

// The org-a read returns the EXACT 6-field present() items in exception_date
// DESC order (the fac-a2 row IS visible to the org-level caller; the org-b
// row is NOT; the CANCELLED row IS returned — no status filter).
const orgAScheduleExceptionsOrdered = [
  { id: EXC_A1_BLK, facilityId: 'fac-a1', staffId: STAFF_A1_DR, exceptionDate: '2026-04-01', reason: 'block', status: 'active' },
  { id: EXC_A1_LV, facilityId: 'fac-a1', staffId: STAFF_A1_DR, exceptionDate: '2026-03-05', reason: 'leave', status: 'active' },
  { id: EXC_A2_LV, facilityId: 'fac-a2', staffId: STAFF_A2_IC, exceptionDate: '2026-02-20', reason: 'leave', status: 'active' },
  { id: EXC_A1_HOL_CXL, facilityId: 'fac-a1', staffId: STAFF_A1_NR, exceptionDate: '2026-02-14', reason: 'holiday', status: 'cancelled' },
];

await test('patients:documents — a receptionist reads the in-scope patient\'s documents (200, exact shape)', async () => {
  const deps = documentsFixtureDeps();
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(Object.keys(body).sort(), ['data', 'links', 'meta']);
  // data is the bare document list (Laravel passes the collection directly).
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 4);
  // The exact 10-field map + key casing; internal fields never leak.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), DOCUMENT_ITEM_KEYS.slice().sort());
  }
  const report = body.data[0];
  assert.equal(report.id, DOC_REPORT);
  assert.equal(report.patientId, PAT_A1);
  assert.equal(report.documentType, 'report');
  assert.equal(report.mimeType, 'application/pdf');
  assert.equal(report.sizeBytes, 1048576);
  assert.equal(report.checksum, 'a1b2c3d4e5f6'.padEnd(64, '0'));
  assert.equal(report.status, 'staged');
  assert.equal(report.uploadedAt, '2026-03-02T11:00:00Z');
  assert.equal(report.expiresAt, null);
  assert.equal(report.retentionClass, 'clinical');
  // Envelope context echo is the authoritative server fact.
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.meta.claimsIssued, true);
});

await test('patients:documents — documents are ordered by created_at DESC (Laravel parity)', async () => {
  const body = await bodyJson(await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, documentsFixtureDeps()));
  assert.equal(body.data.length, 4);
  // Seeded out of order — the read orders by created_at DESC only.
  assert.deepEqual(body.data.map((d) => d.id), [DOC_REPORT, DOC_CONSENT, DOC_ID, DOC_DISCHARGE]);
  assert.equal(body.data[0].status, 'staged');
  assert.equal(body.data[1].status, 'available');
  assert.equal(body.data[2].status, 'archived');
  assert.equal(body.data[3].status, 'purged');
});

await test('patients:documents — NO status filter: staged, available, archived AND purged all return', async () => {
  const body = await bodyJson(await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, documentsFixtureDeps()));
  const statuses = body.data.map((d) => d.status).sort();
  assert.deepEqual(statuses, ['archived', 'available', 'purged', 'staged']);
});

await test('patients:documents — nullable fields render per the contract (fully-nullable metadata row)', async () => {
  const body = await bodyJson(await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, documentsFixtureDeps()));
  const purged = body.data.find((d) => d.status === 'purged');
  assert.equal(purged.id, DOC_DISCHARGE);
  assert.equal(purged.mimeType, null);
  assert.equal(purged.sizeBytes, null);
  assert.equal(purged.checksum, null);
  assert.equal(purged.expiresAt, null);
  assert.equal(purged.retentionClass, null);
  assert.equal(purged.uploadedAt, '2026-03-02T09:00:00Z');
  const consent = body.data.find((d) => d.status === 'available');
  assert.equal(consent.expiresAt, '2027-06-01T00:00:00Z');
  assert.equal(consent.retentionClass, 'legal');
});

await test('patients:documents — the storage pointer objectKey NEVER crosses the boundary (Laravel contract)', async () => {
  const body = await bodyJson(await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, documentsFixtureDeps()));
  const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
  assert.equal(allKeys.has('objectKey'), false, 'objectKey must never be presented');
  for (const item of body.data) {
    assert.equal(Object.prototype.hasOwnProperty.call(item, 'objectKey'), false);
  }
});

await test('patients:documents — an empty document set is an empty array (200)', async () => {
  const deps = makeDeps({}, { seedDocuments: [] });
  const body = await bodyJson(await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps));
  assert.equal(Array.isArray(body.data), true);
  assert.equal(body.data.length, 0);
});

await test('patients:documents — an unauthenticated request is rejected (401)', async () => {
  const response = await handlePatientsDocuments(
    documentsReq(PAT_A1, { Authorization: 'Bearer not-a-jwt' }),
    documentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'INVALID_TOKEN');
});

await test('patients:documents — an expired JWT is rejected (401)', async () => {
  const token = await gotrueToken({ sub: SUB_RECEPTIONIST, exp: NOW - 3600 });
  const response = await handlePatientsDocuments(
    documentsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    documentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
  assert.equal((await bodyJson(response)).error.code, 'TOKEN_EXPIRED');
});

await test('patients:documents — an unknown subject is rejected (401)', async () => {
  const token = await gotrueToken({ sub: 'ffffffff-0000-4000-8000-000000000001' });
  const response = await handlePatientsDocuments(
    documentsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    documentsFixtureDeps(),
  );
  assert.equal(response.status, 401);
});

await test('patients:documents — a locked identity is rejected (403 FORBIDDEN)', async () => {
  const response = await documentsAs(SUB_LOCKED, PAT_A1, {}, documentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:documents — a disabled identity is rejected (403 FORBIDDEN)', async () => {
  const response = await documentsAs(SUB_DISABLED, PAT_A1, {}, documentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:documents — a suspended tenant fails closed (403 TENANT_SUSPENDED)', async () => {
  const suspendedOrg = { id: 'org-suspended', status: 'disabled' };
  const suspendedAdmin = { id: 'u-suspended-admin', email: 's@x.test', status: 'active' };
  const deps = makeDeps({
    findUserBySubject: (sub) => (sub === SUB_RECEPTIONIST ? suspendedAdmin : null),
    loadActiveAssignments: (userId) => userId === 'u-suspended-admin'
      ? [{
          id: 'as-s', userId: 'u-suspended-admin', roleId: 'r-org-admin', tenantId: 'org-suspended',
          facilityId: null, branchId: null, scopeType: 'organization',
          role: { id: 'r-org-admin', code: 'org_admin', scopeType: 'organization', permissions: permissions.orgAdmin },
        }]
      : [],
    loadOrganization: () => suspendedOrg,
  });
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'TENANT_SUSPENDED');
});

await test('patients:documents — missing context (no assignment) fails closed (403 FORBIDDEN)', async () => {
  const response = await documentsAs(SUB_NO_ASSIGNMENT, PAT_A1, {}, documentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FORBIDDEN');
});

await test('patients:documents — a principal without document:view is denied (403 SCOPE_DENIED) with zero mutation', async () => {
  const deps = documentsFixtureDeps();
  const response = await documentsAs(SUB_NO_PERM, PAT_A1, {}, deps);
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
  assert.equal(deps.getAuditEvents().length, 0);
  assert.equal(deps.getDocuments().length, 4);
});

await test('patients:documents — patient:view/insurance:view ALONE is not the gate: a cashier without document:view is denied (403)', async () => {
  // SUB_CASHIER holds patient:view + insurance:view but NOT document:view
  // (the seeded billing_clerk role lacks document:view) — the gate is the
  // exact Laravel `authorize:document:view`.
  const response = await documentsAs(SUB_CASHIER, PAT_A1, {}, documentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'SCOPE_DENIED');
});

await test('patients:documents — a malformed patient id is indistinguishable from a missing resource (404)', async () => {
  const response = await documentsAs(SUB_RECEPTIONIST, 'not-a-uuid', {}, documentsFixtureDeps());
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.message, 'Resource not found.');
});

await test('patients:documents — a nonexistent patient returns 404 (existence never leaked)', async () => {
  const response = await documentsAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000', {}, documentsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('patients:documents — a cross-tenant patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedDocuments: [fullPatientDocument(DOC_REPORT, { tenantId: 'org-b', patientId: PAT_B1 })] });
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_B1, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:documents — a cross-facility patient is invisible (404)', async () => {
  const deps = makeDeps({}, { seedDocuments: [fullPatientDocument(DOC_REPORT, { patientId: PAT_A2 })] });
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A2, {}, deps);
  assert.equal(response.status, 404);
  assert.equal((await bodyJson(response)).error.code, 'NOT_FOUND');
});

await test('patients:documents — forged app_* claims are inert (context/scope stay authoritative)', async () => {
  const token = await gotrueToken({
    sub: SUB_RECEPTIONIST,
    app_user_id: 'u-attacker',
    app_tenant_id: 'org-b',
    app_facility_id: 'fac-b',
    app_is_platform: 'true',
  });
  const response = await handlePatientsDocuments(
    documentsReq(PAT_A1, { Authorization: `Bearer ${token}` }),
    documentsFixtureDeps(),
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.tenantId, 'org-a');
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 4);
  assert.equal(body.data[0].id, DOC_REPORT);
});

await test('patients:documents — a facility proposal outside the caller\'s assignments fails closed (403 FACILITY_DENIED)', async () => {
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Swasthya-Facility': 'fac-b' }, documentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'FACILITY_DENIED');
});

await test('patients:documents — a branch proposal outside the resolved scope fails closed (403 BRANCH_DENIED)', async () => {
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Swasthya-Branch': 'br-b' }, documentsFixtureDeps());
  assert.equal(response.status, 403);
  assert.equal((await bodyJson(response)).error.code, 'BRANCH_DENIED');
});

await test('patients:documents — a facility proposal within the assignments selects scope (still authoritative)', async () => {
  const deps = documentsFixtureDeps();
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Swasthya-Facility': 'fac-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.meta.context.facilityId, 'fac-a1');
  assert.equal(body.data.length, 4);
});

await test('patients:documents — correlation id propagates to the response', async () => {
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, { 'X-Correlation-Id': 'corr-doc-1' }, documentsFixtureDeps());
  assert.equal(response.headers.get('X-Request-Id'), 'corr-doc-1');
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-doc-1');
  assert.equal((await bodyJson(response)).error === undefined, true);
});

await test('patients:documents — a generated correlation id echoes on success and errors', async () => {
  const ok = await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, documentsFixtureDeps());
  const okId = ok.headers.get('X-Correlation-Id');
  assert.ok(okId && okId.length > 0);
  const err = await documentsAs(SUB_RECEPTIONIST, 'ffffffff-0000-4000-8000-000000000000', {}, documentsFixtureDeps());
  const errId = err.headers.get('X-Correlation-Id');
  assert.ok(errId && errId.length > 0);
  assert.equal((await bodyJson(err)).error.correlationId, errId);
});

await test('patients:documents — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = documentsFixtureDeps();
  const before = deps.getDocuments().map((d) => ({ id: d.id, status: d.status, checksum: d.checksum, sizeBytes: d.sizeBytes }));
  const response = await documentsAs(SUB_RECEPTIONIST, PAT_A1, {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getDocuments().map((d) => ({ id: d.id, status: d.status, checksum: d.checksum, sizeBytes: d.sizeBytes }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
  // Internal fields never surface in the payload (patientId IS the
  // contract; the storage pointer objectKey must never leak).
  const body = await bodyJson(response);
  const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
  for (const forbidden of ['tenantId', 'facilityId', 'objectKey', 'uploadedBy', 'parentDocumentId', 'createdAt', 'updatedAt']) {
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

/* ------------------------------------------------------------------ */
/* Phase 35 — organizations:locations                                   */
/* ------------------------------------------------------------------ */

function locationsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-locations/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function locationsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsLocations(
    locationsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// The org-a read returns the EXACT 7-field present() items in name order.
const orgALocationsOrdered = [
  { id: LOC_A1_STORE, facilityId: 'fac-a1', branchId: 'br-a1', name: 'Central Store', code: 'STORE', type: 'store', status: 'active' },
  { id: LOC_A1_NURSE, facilityId: 'fac-a1', branchId: null, name: 'Nursing Station', code: 'NURSE', type: 'nursing_station', status: 'inactive' },
  { id: LOC_A2_PROC, facilityId: 'fac-a2', branchId: 'br-a2', name: 'Procedure Suite', code: 'PROC', type: 'procedure_area', status: 'active' },
  { id: LOC_A1_WAIT, facilityId: 'fac-a1', branchId: 'br-a1', name: 'Reception Waiting', code: 'WAIT', type: 'waiting_area', status: 'active' },
];

await test('organizations:locations — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsLocations(locationsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:locations — the gate is location:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT location:view (the seeded doctor
  // role lacks it). Gate distinctness.
  const response = await locationsAs(SUB_FAC_DOCTOR, 'org-a', {}, locationsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:locations — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await locationsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, locationsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:locations — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await locationsAs(SUB_TENANT_ADMIN, 'org-b', {}, locationsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:locations — org-level read: every facility of the tenant, ordered by name ASC, no status filter', async () => {
  const deps = locationsFixtureDeps();
  const response = await locationsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  // The exact Laravel order (->orderBy('name')); the org-a/fac-a2 row IS
  // visible to the org-level caller (no facility filter); the org-b row is
  // NOT.
  assert.deepEqual(body.data, orgALocationsOrdered);
  // NO status filter: the inactive Nursing Station row is present.
  assert.equal(body.data.find((d) => d.id === LOC_A1_NURSE).status, 'inactive');
  // Nullable + hydrated contract: Nursing Station has a NULL branch; the
  // others carry their real facility/branch ids.
  assert.equal(body.data.find((d) => d.id === LOC_A1_NURSE).branchId, null);
  assert.equal(body.data.find((d) => d.id === LOC_A1_STORE).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === LOC_A1_STORE).branchId, 'br-a1');
  // Exact key set — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...LOCATION_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:locations — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = locationsFixtureDeps();
  const response = await locationsAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 3);
  assert.deepEqual(body.data.map((d) => d.name), ['Central Store', 'Nursing Station', 'Reception Waiting']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === LOC_A2_PROC), false);
  assert.equal(body.data.some((d) => d.id === LOC_B_STORE), false);
});

await test('organizations:locations — branch scope narrows via the validated X-Swasthya-Branch proposal', async () => {
  // The hospital admin (fac-a1) proposes br-a1 → branch claim br-a1 → the
  // TENANT_FACILITY_BRANCH branch clause keeps br-a1 rows + branch-less
  // rows.
  const deps = locationsFixtureDeps();
  const response = await locationsAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.name), ['Central Store', 'Nursing Station', 'Reception Waiting']);
  assert.equal(body.data.some((d) => d.id === LOC_A2_PROC), false);
  assert.equal(body.data.some((d) => d.id === LOC_B_STORE), false);
});

await test('organizations:locations — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = locationsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsLocations(
    locationsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgALocationsOrdered);
  assert.equal(body.data.some((d) => d.id === LOC_B_STORE), false);
});

await test('organizations:locations — empty organization has an empty list (bare array, never null)', async () => {
  const deps = locationsFixtureDeps({ locations: [] });
  const response = await locationsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:locations — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = locationsFixtureDeps();
  const before = deps.getLocations().map((l) => ({ id: l.id, name: l.name, status: l.status }));
  const response = await locationsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getLocations().map((l) => ({ id: l.id, name: l.name, status: l.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:locations — correlation id is echoed', async () => {
  const deps = locationsFixtureDeps();
  const response = await locationsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase35' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase35');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase35');
});

/* ------------------------------------------------------------------ */
/* Phase 36 — organizations:wards                                       */
/* ------------------------------------------------------------------ */

function wardsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-wards/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function wardsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsWards(
    wardsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// The org-a read returns the EXACT 7-field present() items in name order.
const orgAWardsOrdered = [
  { id: WARD_A1_GEN, facilityId: 'fac-a1', branchId: 'br-a1', name: 'General Ward', code: 'GEN', wardType: 'general', status: 'active' },
  { id: WARD_A1_ICU, facilityId: 'fac-a1', branchId: 'br-a1', name: 'Intensive Care', code: 'ICU', wardType: 'icu', status: 'active' },
  { id: WARD_A1_OTHERBR, facilityId: 'fac-a1', branchId: 'br-a1b', name: 'Maternity Wing A', code: 'MAT1', wardType: 'maternity', status: 'active' },
  { id: WARD_A1_PED, facilityId: 'fac-a1', branchId: null, name: 'Pediatric', code: 'PED', wardType: 'pediatric', status: 'inactive' },
  { id: WARD_A2_SUR, facilityId: 'fac-a2', branchId: 'br-a2', name: 'Surgery', code: 'SUR', wardType: 'surgery', status: 'active' },
];

await test('organizations:wards — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsWards(wardsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:wards — the gate is ward:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT ward:view (the seeded doctor role
  // lacks it). Gate distinctness.
  const response = await wardsAs(SUB_FAC_DOCTOR, 'org-a', {}, wardsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:wards — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await wardsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, wardsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:wards — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await wardsAs(SUB_TENANT_ADMIN, 'org-b', {}, wardsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:wards — org-level read: every facility of the tenant, ordered by name ASC, no status filter', async () => {
  const deps = wardsFixtureDeps();
  const response = await wardsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 5);
  // The exact Laravel order (->orderBy('name')); the org-a/fac-a2 row IS
  // visible to the org-level caller (no facility filter); the org-b row is
  // NOT.
  assert.deepEqual(body.data, orgAWardsOrdered);
  // NO status filter: the inactive Pediatric row is present.
  assert.equal(body.data.find((d) => d.id === WARD_A1_PED).status, 'inactive');
  // Nullable + hydrated contract: Pediatric has a NULL branch; the others
  // carry their real facility/branch ids.
  assert.equal(body.data.find((d) => d.id === WARD_A1_PED).branchId, null);
  assert.equal(body.data.find((d) => d.id === WARD_A1_GEN).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === WARD_A1_GEN).branchId, 'br-a1');
  // The wardType enum values: general/surgery/pediatric/icu/maternity/other.
  assert.deepEqual(body.data.map((d) => d.wardType).sort(), ['general', 'icu', 'maternity', 'pediatric', 'surgery']);
  // Exact key set — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...WARD_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:wards — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = wardsFixtureDeps();
  const response = await wardsAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  assert.deepEqual(body.data.map((d) => d.name), ['General Ward', 'Intensive Care', 'Maternity Wing A', 'Pediatric']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === WARD_A2_SUR), false);
  assert.equal(body.data.some((d) => d.id === WARD_B_MAT), false);
});

await test('organizations:wards — branch scope narrows via the validated X-Swasthya-Branch proposal', async () => {
  // The hospital admin (fac-a1) proposes br-a1 → branch claim br-a1 → the
  // TENANT_FACILITY_BRANCH branch clause keeps br-a1 rows + branch-less
  // rows; the wrong-branch (br-a1b) Maternity Wing A row is invisible.
  const deps = wardsFixtureDeps();
  const response = await wardsAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.name), ['General Ward', 'Intensive Care', 'Pediatric']);
  assert.equal(body.data.some((d) => d.id === WARD_A1_OTHERBR), false);
  assert.equal(body.data.some((d) => d.id === WARD_A2_SUR), false);
  assert.equal(body.data.some((d) => d.id === WARD_B_MAT), false);
});

await test('organizations:wards — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = wardsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsWards(
    wardsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAWardsOrdered);
  assert.equal(body.data.some((d) => d.id === WARD_B_MAT), false);
});

await test('organizations:wards — empty organization has an empty list (bare array, never null)', async () => {
  const deps = wardsFixtureDeps({ wards: [] });
  const response = await wardsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:wards — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = wardsFixtureDeps();
  const before = deps.getWards().map((w) => ({ id: w.id, name: w.name, status: w.status }));
  const response = await wardsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getWards().map((w) => ({ id: w.id, name: w.name, status: w.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:wards — correlation id is echoed', async () => {
  const deps = wardsFixtureDeps();
  const response = await wardsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase36' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase36');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase36');
});

/* ------------------------------------------------------------------ */
/* Phase 37 — organizations:rooms                                       */
/* ------------------------------------------------------------------ */

function roomsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-rooms/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function roomsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsRooms(
    roomsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:rooms — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsRooms(roomsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:rooms — the gate is room:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT room:view (the seeded doctor role
  // lacks it). Gate distinctness.
  const response = await roomsAs(SUB_FAC_DOCTOR, 'org-a', {}, roomsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:rooms — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await roomsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, roomsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:rooms — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await roomsAs(SUB_TENANT_ADMIN, 'org-b', {}, roomsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:rooms — org-level read: every facility of the tenant, ordered by name ASC, no status filter', async () => {
  const deps = roomsFixtureDeps();
  const response = await roomsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 5);
  // The exact Laravel order (->orderBy('name')); the org-a/fac-a2 row IS
  // visible to the org-level caller (no facility filter); the org-b row is
  // NOT.
  assert.deepEqual(body.data, orgARoomsOrdered);
  // NO status filter: the inactive Semi-Private row is present.
  assert.equal(body.data.find((d) => d.id === ROOM_A1_SEMI).status, 'inactive');
  // Nullable + hydrated contract: Semi-Private has a NULL branch; the
  // others carry their real facility/branch/ward ids.
  assert.equal(body.data.find((d) => d.id === ROOM_A1_SEMI).branchId, null);
  assert.equal(body.data.find((d) => d.id === ROOM_A1_GEN).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === ROOM_A1_GEN).branchId, 'br-a1');
  assert.equal(body.data.find((d) => d.id === ROOM_A1_GEN).wardId, WARD_A1_GEN);
  // The eager ward ref carries exactly id/code/name.
  assert.deepEqual(body.data.find((d) => d.id === ROOM_A1_GEN).ward, { id: WARD_A1_GEN, code: 'GEN', name: 'General Ward' });
  assert.deepEqual(body.data.find((d) => d.id === ROOM_A2_ICU).ward, { id: WARD_A2_SUR, code: 'SUR', name: 'Surgery' });
  // The roomType enum values: general/private/semi_private/icu/other.
  assert.deepEqual(body.data.map((d) => d.roomType).sort(), ['general', 'icu', 'other', 'private', 'semi_private']);
  // Money + currency contract: minor units (integers), nullable currency.
  assert.equal(body.data.find((d) => d.id === ROOM_A1_GEN).dailyRateMinor, 1000);
  assert.equal(body.data.find((d) => d.id === ROOM_A1_GEN).currency, 'NPR');
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...ROOM_ITEM_KEYS]);
    if (item.ward !== null) {
      assert.deepEqual(Object.keys(item.ward).sort(), [...WARD_REF_KEYS]);
    }
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:rooms — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = roomsFixtureDeps();
  const response = await roomsAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  assert.deepEqual(body.data.map((d) => d.name), ['General Room', 'Maternity Room', 'Private Suite', 'Semi-Private']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === ROOM_A2_ICU), false);
  assert.equal(body.data.some((d) => d.id === ROOM_B_STD), false);
});

await test('organizations:rooms — branch scope narrows via the validated X-Swasthya-Branch proposal', async () => {
  // The hospital admin (fac-a1) proposes br-a1 → branch claim br-a1 → the
  // TENANT_FACILITY_BRANCH branch clause keeps br-a1 rows + branch-less
  // rows; the wrong-branch (br-a1b) Maternity Room row is invisible.
  const deps = roomsFixtureDeps();
  const response = await roomsAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.name), ['General Room', 'Private Suite', 'Semi-Private']);
  assert.equal(body.data.some((d) => d.id === ROOM_A1_OTHERBR), false);
  assert.equal(body.data.some((d) => d.id === ROOM_A2_ICU), false);
  assert.equal(body.data.some((d) => d.id === ROOM_B_STD), false);
});

await test('organizations:rooms — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = roomsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsRooms(
    roomsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgARoomsOrdered);
  assert.equal(body.data.some((d) => d.id === ROOM_B_STD), false);
});

await test('organizations:rooms — empty organization has an empty list (bare array, never null)', async () => {
  const deps = roomsFixtureDeps({ rooms: [] });
  const response = await roomsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:rooms — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = roomsFixtureDeps();
  const before = deps.getRooms().map((r) => ({ id: r.id, name: r.name, status: r.status }));
  const response = await roomsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getRooms().map((r) => ({ id: r.id, name: r.name, status: r.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:rooms — correlation id is echoed', async () => {
  const deps = roomsFixtureDeps();
  const response = await roomsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase37' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase37');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase37');
});

/* ------------------------------------------------------------------ */
/* Phase 38 — organizations:beds                                        */
/* ------------------------------------------------------------------ */

function bedsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-beds/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function bedsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsBeds(
    bedsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:beds — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsBeds(bedsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:beds — the gate is bed:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT bed:view (the seeded doctor role
  // lacks it). Gate distinctness.
  const response = await bedsAs(SUB_FAC_DOCTOR, 'org-a', {}, bedsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:beds — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await bedsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, bedsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:beds — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await bedsAs(SUB_TENANT_ADMIN, 'org-b', {}, bedsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:beds — org-level read: every facility of the tenant, ordered by bed_code ASC, no status filter', async () => {
  const deps = bedsFixtureDeps();
  const response = await bedsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 5);
  // The exact Laravel order (->orderBy('bed_code')); the org-a/fac-a2 row
  // IS visible to the org-level caller (no facility filter); the org-b row
  // is NOT.
  assert.deepEqual(body.data, orgABedsOrdered);
  // NO status filter: the out_of_service row is present (never
  // soft-deleted — out_of_service is a status).
  assert.equal(body.data.find((d) => d.id === BED_A1_03).status, 'out_of_service');
  // Nullable + hydrated contract: the branch-less bed has a NULL branch;
  // the others carry their real facility/branch/room ids.
  assert.equal(body.data.find((d) => d.id === BED_A1_03).branchId, null);
  assert.equal(body.data.find((d) => d.id === BED_A1_01).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === BED_A1_01).branchId, 'br-a1');
  assert.equal(body.data.find((d) => d.id === BED_A1_01).roomId, ROOM_A1_GEN);
  // The eager room ref carries exactly id/code/name (never wardId).
  assert.deepEqual(body.data.find((d) => d.id === BED_A1_01).room, { id: ROOM_A1_GEN, code: 'R-GEN', name: 'General Room' });
  assert.deepEqual(body.data.find((d) => d.id === BED_A2_01).room, { id: ROOM_A2_ICU, code: 'R-ICU', name: 'ICU Bay' });
  // The BedStatus lifecycle statuses: available/occupied/reserved/
  // cleaning/out_of_service.
  assert.deepEqual(body.data.map((d) => d.status).sort(), ['available', 'cleaning', 'occupied', 'out_of_service', 'reserved']);
  // lockVersion is CONTRACT-EXPLICIT — the optimistic-locking counter.
  assert.equal(body.data.find((d) => d.id === BED_A1_01).lockVersion, 0);
  assert.equal(body.data.find((d) => d.id === BED_A1_03).lockVersion, 5);
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...BED_ITEM_KEYS]);
    if (item.room !== null) {
      assert.deepEqual(Object.keys(item.room).sort(), [...ROOM_REF_KEYS]);
    }
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy', 'currentAdmissionId']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:beds — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = bedsFixtureDeps();
  const response = await bedsAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  assert.deepEqual(body.data.map((d) => d.bedCode), ['B-01', 'B-02', 'B-03', 'B-04']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === BED_A2_01), false);
  assert.equal(body.data.some((d) => d.id === BED_B_01), false);
});

await test('organizations:beds — branch scope narrows via the validated X-Swasthya-Branch proposal', async () => {
  // The hospital admin (fac-a1) proposes br-a1 → branch claim br-a1 → the
  // TENANT_FACILITY_BRANCH branch clause keeps br-a1 rows + branch-less
  // rows; the wrong-branch (br-a1b) reserved bed row is invisible.
  const deps = bedsFixtureDeps();
  const response = await bedsAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.bedCode), ['B-01', 'B-02', 'B-03']);
  assert.equal(body.data.some((d) => d.id === BED_A1_OTHERBR), false);
  assert.equal(body.data.some((d) => d.id === BED_A2_01), false);
  assert.equal(body.data.some((d) => d.id === BED_B_01), false);
});

await test('organizations:beds — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = bedsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsBeds(
    bedsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgABedsOrdered);
  assert.equal(body.data.some((d) => d.id === BED_B_01), false);
});

await test('organizations:beds — empty organization has an empty list (bare array, never null)', async () => {
  const deps = bedsFixtureDeps({ beds: [] });
  const response = await bedsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:beds — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = bedsFixtureDeps();
  const before = deps.getBeds().map((b) => ({ id: b.id, bedCode: b.bedCode, status: b.status, lockVersion: b.lockVersion }));
  const response = await bedsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getBeds().map((b) => ({ id: b.id, bedCode: b.bedCode, status: b.status, lockVersion: b.lockVersion }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:beds — correlation id is echoed', async () => {
  const deps = bedsFixtureDeps();
  const response = await bedsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase38' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase38');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase38');
});

/* ------------------------------------------------------------------ */
/* Phase 39 — organizations:staff                                       */
/* ------------------------------------------------------------------ */

function staffReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-staff/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function staffAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsStaff(
    staffReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:staff — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsStaff(staffReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:staff — the gate is staff:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT staff:view (the seeded doctor role
  // lacks it). Gate distinctness.
  const response = await staffAs(SUB_FAC_DOCTOR, 'org-a', {}, staffFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:staff — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await staffAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, staffFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:staff — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await staffAs(SUB_TENANT_ADMIN, 'org-b', {}, staffFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:staff — org-level read: every facility of the tenant, ordered by full_name ASC, no status filter', async () => {
  const deps = staffFixtureDeps();
  const response = await staffAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 5);
  // The exact Laravel order (->orderBy('full_name')); the org-a/fac-a2 row
  // IS visible to the org-level caller (no facility filter); the org-b row
  // is NOT.
  assert.deepEqual(body.data, orgAStaffOrdered);
  // NO status filter: the on_leave and departed rows are present (never
  // soft-deleted — departed is a status).
  assert.equal(body.data.find((d) => d.id === STAFF_A1_LB).status, 'on_leave');
  assert.equal(body.data.find((d) => d.id === STAFF_A1_GD).status, 'departed');
  // Nullable + hydrated contract: the departed row has a NULL department
  // ref (soft-deleted department → null, never a leak), NULL designation;
  // the on_leave row has NULL userId + hireDate.
  assert.equal(body.data.find((d) => d.id === STAFF_A1_GD).department, null);
  assert.equal(body.data.find((d) => d.id === STAFF_A1_GD).designation, null);
  assert.equal(body.data.find((d) => d.id === STAFF_A1_LB).userId, null);
  assert.equal(body.data.find((d) => d.id === STAFF_A1_LB).hireDate, null);
  // Hydrated real ids + the eager department ref (exactly id/code/name).
  assert.equal(body.data.find((d) => d.id === STAFF_A1_DR).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === STAFF_A1_DR).departmentId, DEPT_CARD);
  assert.deepEqual(body.data.find((d) => d.id === STAFF_A1_DR).department, { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' });
  assert.deepEqual(body.data.find((d) => d.id === STAFF_A2_IC).department, { id: DEPT_SUR, code: 'SUR', name: 'Surgery' });
  // The staff status lifecycle: active/on_leave/departed.
  assert.deepEqual(body.data.map((d) => d.status).sort(), ['active', 'active', 'active', 'departed', 'on_leave']);
  // hireDate is the date cast's toDateString (YYYY-MM-DD).
  assert.equal(body.data.find((d) => d.id === STAFF_A1_DR).hireDate, '2024-01-15');
  assert.equal(body.data.find((d) => d.id === STAFF_A1_NR).hireDate, '2023-06-01');
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...STAFF_ITEM_KEYS]);
    if (item.department !== null) {
      assert.deepEqual(Object.keys(item.department).sort(), [...DEPT_REF_KEYS]);
    }
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy', 'licenseNumberEncrypted', 'licenseNumber', 'settings']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:staff — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = staffFixtureDeps();
  const response = await staffAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  assert.deepEqual(body.data.map((d) => d.fullName), ['Aarav Sharma', 'Bina Gurung', 'Chandra Rai', 'Dawa Sherpa']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === STAFF_A2_IC), false);
  assert.equal(body.data.some((d) => d.id === STAFF_B_DR), false);
});

await test('organizations:staff — a branch proposal does NOT narrow the read (staff is TENANT_FACILITY, no branch dimension)', async () => {
  // staff has NO branch_id column — the TENANT_FACILITY select policy is
  // `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)`
  // with NO branch clause. The validated X-Swasthya-Branch proposal is
  // accepted (context validation) but is irrelevant to the read: every
  // fac-a1 row stays visible.
  const deps = staffFixtureDeps();
  const response = await staffAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.fullName), ['Aarav Sharma', 'Bina Gurung', 'Chandra Rai', 'Dawa Sherpa']);
  assert.equal(body.data.some((d) => d.id === STAFF_A2_IC), false);
  assert.equal(body.data.some((d) => d.id === STAFF_B_DR), false);
});

await test('organizations:staff — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = staffFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsStaff(
    staffReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAStaffOrdered);
  assert.equal(body.data.some((d) => d.id === STAFF_B_DR), false);
});

await test('organizations:staff — empty organization has an empty list (bare array, never null)', async () => {
  const deps = staffFixtureDeps({ staff: [] });
  const response = await staffAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:staff — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = staffFixtureDeps();
  const before = deps.getStaff().map((s) => ({ id: s.id, fullName: s.fullName, status: s.status }));
  const response = await staffAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getStaff().map((s) => ({ id: s.id, fullName: s.fullName, status: s.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:staff — correlation id is echoed', async () => {
  const deps = staffFixtureDeps();
  const response = await staffAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase39' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase39');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase39');
});

/* ------------------------------------------------------------------ */
/* Phase 40 — organizations:services                                    */
/* ------------------------------------------------------------------ */

function servicesReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-services/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function servicesAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsServices(
    servicesReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:services — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsServices(servicesReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:services — the gate is service:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT service:view (the seeded doctor role
  // lacks it). Gate distinctness.
  const response = await servicesAs(SUB_FAC_DOCTOR, 'org-a', {}, servicesFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:services — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await servicesAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, servicesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:services — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await servicesAs(SUB_TENANT_ADMIN, 'org-b', {}, servicesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:services — org-level read: every facility of the tenant, ordered by name ASC, no status filter, soft-deleted excluded', async () => {
  const deps = servicesFixtureDeps();
  const response = await servicesAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  // The exact Laravel order (->orderBy('name')); the org-a/fac-a2 row IS
  // visible to the org-level caller (no facility filter); the org-b row is
  // NOT; the SOFT-DELETED Follow-up row is EXCLUDED (the SoftDeletes model
  // scope).
  assert.deepEqual(body.data, orgAServicesOrdered);
  assert.equal(body.data.some((d) => d.id === SVC_A1_DEL), false);
  // NO status filter: the inactive Lab Investigation row is present.
  assert.equal(body.data.find((d) => d.id === SVC_A1_LAB).status, 'inactive');
  // Nullable + hydrated contract: the Lab row has a NULL departmentId,
  // NULL duration/charge/currency (department-less service); the OPD row
  // carries the real facility/department ids + the eager dept ref.
  assert.equal(body.data.find((d) => d.id === SVC_A1_LAB).departmentId, null);
  assert.equal(body.data.find((d) => d.id === SVC_A1_LAB).department, null);
  assert.equal(body.data.find((d) => d.id === SVC_A1_LAB).defaultDurationMinutes, null);
  assert.equal(body.data.find((d) => d.id === SVC_A1_LAB).defaultChargeMinor, null);
  assert.equal(body.data.find((d) => d.id === SVC_A1_LAB).currency, null);
  assert.equal(body.data.find((d) => d.id === SVC_A1_OPD).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === SVC_A1_OPD).departmentId, DEPT_CARD);
  assert.deepEqual(body.data.find((d) => d.id === SVC_A1_OPD).department, { id: DEPT_CARD, code: 'CARD', name: 'Cardiology' });
  assert.deepEqual(body.data.find((d) => d.id === SVC_A2_SUR).department, { id: DEPT_SUR, code: 'SUR', name: 'Surgery' });
  // The serviceType enum values: opd_consultation/procedure/investigation/follow_up/other.
  assert.deepEqual(body.data.map((d) => d.serviceType).sort(), ['investigation', 'opd_consultation', 'procedure', 'procedure']);
  // Money + duration contract: integer minor units, nullable currency.
  assert.equal(body.data.find((d) => d.id === SVC_A1_OPD).defaultDurationMinutes, 15);
  assert.equal(body.data.find((d) => d.id === SVC_A1_OPD).defaultChargeMinor, 50000);
  assert.equal(body.data.find((d) => d.id === SVC_A1_OPD).currency, 'NPR');
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...SERVICE_ITEM_KEYS]);
    if (item.department !== null) {
      assert.deepEqual(Object.keys(item.department).sort(), [...SERVICE_DEPT_REF_KEYS]);
    }
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:services — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = servicesFixtureDeps();
  const response = await servicesAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 3);
  assert.deepEqual(body.data.map((d) => d.name), ['Lab Investigation', 'OPD Consultation', 'Procedure']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === SVC_A2_SUR), false);
  assert.equal(body.data.some((d) => d.id === SVC_B_ONC), false);
  assert.equal(body.data.some((d) => d.id === SVC_A1_DEL), false);
});

await test('organizations:services — a branch proposal does NOT narrow the read (services is TENANT_FACILITY, no branch dimension)', async () => {
  // services has NO branch_id column — the TENANT_FACILITY select policy is
  // `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS NULL)`
  // with NO branch clause. The validated X-Swasthya-Branch proposal is
  // accepted (context validation) but is irrelevant to the read: every
  // fac-a1 row stays visible.
  const deps = servicesFixtureDeps();
  const response = await servicesAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.name), ['Lab Investigation', 'OPD Consultation', 'Procedure']);
  assert.equal(body.data.some((d) => d.id === SVC_A2_SUR), false);
  assert.equal(body.data.some((d) => d.id === SVC_B_ONC), false);
});

await test('organizations:services — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = servicesFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsServices(
    servicesReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAServicesOrdered);
  assert.equal(body.data.some((d) => d.id === SVC_B_ONC), false);
});

await test('organizations:services — empty organization has an empty list (bare array, never null)', async () => {
  const deps = servicesFixtureDeps({ services: [] });
  const response = await servicesAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:services — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = servicesFixtureDeps();
  const before = deps.getServices().map((s) => ({ id: s.id, name: s.name, status: s.status, deletedAt: s.deletedAt }));
  const response = await servicesAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getServices().map((s) => ({ id: s.id, name: s.name, status: s.status, deletedAt: s.deletedAt }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:services — correlation id is echoed', async () => {
  const deps = servicesFixtureDeps();
  const response = await servicesAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase40' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase40');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase40');
});

/* ------------------------------------------------------------------ */
/* Phase 41 — organizations:payers                                      */
/* ------------------------------------------------------------------ */

function payersReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-payers/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function payersAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsPayers(
    payersReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:payers — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsPayers(payersReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:payers — the gate is payer:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient/insurance/consent/document/queue/encounter/
  // billing view permissions — but NOT payer:view (the seeded doctor role
  // lacks it — RolePermissionSeeder doctor block has no payer:view). Gate
  // distinctness.
  const response = await payersAs(SUB_FAC_DOCTOR, 'org-a', {}, payersFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:payers — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await payersAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, payersFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:payers — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await payersAs(SUB_TENANT_ADMIN, 'org-b', {}, payersFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:payers — org-level read: ordered by name ASC, no status filter, exact 5-field shape', async () => {
  const deps = payersFixtureDeps();
  const response = await payersAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  // The exact Laravel order (->orderBy('name')); the org-b row is NOT.
  assert.deepEqual(body.data, orgAPayersOrdered);
  assert.equal(body.data.some((d) => d.id === PYR_B_TPA), false);
  // NO status filter: the INACTIVE Walk-in Self Pay row is present.
  assert.equal(body.data.find((d) => d.id === PYR_A_OTHER).status, 'inactive');
  // All four payer_type values covered.
  assert.deepEqual(body.data.map((d) => d.payerType).sort(), ['government', 'other', 'private', 'tpa']);
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...PAYER_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'facilityId', 'createdAt', 'updatedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:payers — facility-scoped read: EVERY tenant payer (payers is TENANT_ONLY — no facility dimension)', async () => {
  // payers has NO facility_id column and the Laravel query has NO facility
  // filter (the `! isPlatform && facilityId() !== null` guard is ABSENT) —
  // so even a facility-scoped caller sees every payer of the tenant. This
  // is the material TENANT_ONLY difference from the TENANT_FACILITY
  // catalog reads (staff/services).
  const deps = payersFixtureDeps();
  const response = await payersAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAPayersOrdered);
  assert.equal(body.data.some((d) => d.id === PYR_B_TPA), false);
});

await test('organizations:payers — a branch proposal does NOT narrow the read (no branch dimension at all)', async () => {
  // payers has NO branch_id column — the TENANT_ONLY select policy is just
  // `tenant_id = TENANT`. The validated X-Swasthya-Branch proposal is
  // accepted (context validation) but is irrelevant to the read.
  const deps = payersFixtureDeps();
  const response = await payersAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAPayersOrdered);
  assert.equal(body.data.some((d) => d.id === PYR_B_TPA), false);
});

await test('organizations:payers — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = payersFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsPayers(
    payersReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAPayersOrdered);
  assert.equal(body.data.some((d) => d.id === PYR_B_TPA), false);
});

await test('organizations:payers — empty organization has an empty list (bare array, never null)', async () => {
  const deps = payersFixtureDeps({ payers: [] });
  const response = await payersAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:payers — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = payersFixtureDeps();
  const before = deps.getPayers().map((p) => ({ id: p.id, name: p.name, status: p.status }));
  const response = await payersAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getPayers().map((p) => ({ id: p.id, name: p.name, status: p.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:payers — correlation id is echoed', async () => {
  const deps = payersFixtureDeps();
  const response = await payersAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase41' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase41');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase41');
});

/* ------------------------------------------------------------------ */
/* Phase 42 — organizations:medications                                 */
/* ------------------------------------------------------------------ */

function medicationsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-medications/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function medicationsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsMedications(
    medicationsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:medications — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsMedications(medicationsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:medications — the gate is medication:view, DISTINCT from the related reads', async () => {
  // The receptionist holds patient/consent/document/payer/schedule/
  // appointment/queue view permissions — but NOT medication:view (the
  // seeded receptionist role lacks it — RolePermissionSeeder receptionist
  // block has no medication:view). Gate distinctness. (Unlike the catalog
  // gates of Phases 33–41, the DOCTOR DOES hold medication:view — the
  // seeded doctor prescribes from the formulary.)
  const response = await medicationsAs(SUB_RECEPTIONIST, 'org-a', {}, medicationsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:medications — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await medicationsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, medicationsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:medications — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await medicationsAs(SUB_TENANT_ADMIN, 'org-b', {}, medicationsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:medications — org-level read: every facility of the tenant, ordered by generic_name ASC, no status filter, soft-deleted excluded', async () => {
  const deps = medicationsFixtureDeps();
  const response = await medicationsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  // The exact Laravel order (->orderBy('generic_name')); the org-a/fac-a2
  // row IS visible to the org-level caller (no facility filter); the org-b
  // row is NOT; the SOFT-DELETED Delisted Syrup row is EXCLUDED (the
  // SoftDeletes model scope).
  assert.deepEqual(body.data, orgAMedicationsOrdered);
  assert.equal(body.data.some((d) => d.id === MED_A1_DEL), false);
  // NO status filter: the inactive Ibuprofen row is present.
  assert.equal(body.data.find((d) => d.id === MED_A1_IBU).status, 'inactive');
  // Nullable + hydrated contract: the Metformin row has a NULL brandName
  // (the only nullable text field); the other rows carry the real
  // facility ids + the formulary fields.
  assert.equal(body.data.find((d) => d.id === MED_A1_MET).brandName, null);
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).facilityId, 'fac-a1');
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).brandName, 'Amoxil');
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).strength, '250mg');
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).form, 'capsule');
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).unit, 'cap');
  // Money + boolean contract: integer minor units, 3-char currency,
  // isControlled boolean (false here, true on the org-b controlled row).
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).priceMinor, 30000);
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).currency, 'NPR');
  assert.equal(body.data.find((d) => d.id === MED_A1_AMOX).isControlled, false);
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...MEDICATION_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'facilityId_extra', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy', 'lockVersion']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:medications — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  // The DOCTOR is the facility-scoped success actor — the seeded doctor
  // role holds medication:view (RolePermissionSeeder doctor block).
  const deps = medicationsFixtureDeps();
  const response = await medicationsAs(SUB_FAC_DOCTOR, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 3);
  assert.deepEqual(body.data.map((d) => d.genericName), ['Amoxicillin', 'Ibuprofen', 'Metformin']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === MED_A2_PARA), false);
  assert.equal(body.data.some((d) => d.id === MED_B_INSULIN), false);
  assert.equal(body.data.some((d) => d.id === MED_A1_DEL), false);
});

await test('organizations:medications — a branch proposal does NOT narrow the read (medications is TENANT_FACILITY, no branch dimension)', async () => {
  // medications has NO branch_id column — the TENANT_FACILITY select policy
  // is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY IS
  // NULL)` with NO branch clause. The validated X-Swasthya-Branch proposal
  // is accepted (context validation) but is irrelevant to the read: every
  // fac-a1 row stays visible.
  const deps = medicationsFixtureDeps();
  const response = await medicationsAs(SUB_FAC_DOCTOR, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.genericName), ['Amoxicillin', 'Ibuprofen', 'Metformin']);
  assert.equal(body.data.some((d) => d.id === MED_A2_PARA), false);
  assert.equal(body.data.some((d) => d.id === MED_B_INSULIN), false);
});

await test('organizations:medications — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = medicationsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsMedications(
    medicationsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAMedicationsOrdered);
  assert.equal(body.data.some((d) => d.id === MED_B_INSULIN), false);
});

await test('organizations:medications — empty organization has an empty list (bare array, never null)', async () => {
  const deps = medicationsFixtureDeps({ medications: [] });
  const response = await medicationsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:medications — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = medicationsFixtureDeps();
  const before = deps.getMedications().map((m) => ({ id: m.id, genericName: m.genericName, status: m.status, deletedAt: m.deletedAt }));
  const response = await medicationsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getMedications().map((m) => ({ id: m.id, genericName: m.genericName, status: m.status, deletedAt: m.deletedAt }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:medications — correlation id is echoed', async () => {
  const deps = medicationsFixtureDeps();
  const response = await medicationsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase42' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase42');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase42');
});

/* ------------------------------------------------------------------ */
/* Phase 43 — organizations:schedule-templates                          */
/* ------------------------------------------------------------------ */

function scheduleTemplatesReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-schedule-templates/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function scheduleTemplatesAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsScheduleTemplates(
    scheduleTemplatesReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:schedule-templates — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsScheduleTemplates(scheduleTemplatesReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:schedule-templates — the gate is schedule:view, DISTINCT from the related reads', async () => {
  // The billing_clerk holds patient/insurance/payer/appointment/queue/
  // encounter/billing view permissions — but NOT schedule:view (the
  // seeded billing_clerk role lacks it — RolePermissionSeeder billing_clerk
  // block has no schedule:view). Gate distinctness. (The doctor + nurse
  // DO hold schedule:view — the seeded clinical roles read the schedule.)
  const response = await scheduleTemplatesAs(SUB_CASHIER, 'org-a', {}, scheduleTemplatesFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:schedule-templates — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await scheduleTemplatesAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, scheduleTemplatesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:schedule-templates — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await scheduleTemplatesAs(SUB_TENANT_ADMIN, 'org-b', {}, scheduleTemplatesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:schedule-templates — org-level read: every facility of the tenant, ordered by day_of_week ASC, no status filter, soft-deleted excluded', async () => {
  const deps = scheduleTemplatesFixtureDeps();
  const response = await scheduleTemplatesAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 5);
  // The exact Laravel order (->orderBy('day_of_week')); the org-a/fac-a2
  // row IS visible to the org-level caller (no facility filter); the org-b
  // row is NOT; the SOFT-DELETED row is EXCLUDED (the SoftDeletes model
  // scope).
  assert.deepEqual(body.data, orgAScheduleTemplatesOrdered);
  assert.equal(body.data.some((d) => d.id === TEM_A1_DEL), false);
  // NO status filter: the inactive Tuesday row is present.
  assert.equal(body.data.find((d) => d.id === TEM_A1_TUE).status, 'inactive');
  // Nullable + hydrated contract: the Monday row carries the real facility
  // id + the eager staff ref (id/fullName/designation); the Sunday row the
  // nurse ref; the no-service row has a NULL serviceId (the nullable proof)
  // and its staff ref carries the nullable designation (staff has NO
  // SoftDeletes — the composite FK RESTRICT keeps the ref always
  // resolvable in a consistent DB, so the Laravel `?: null` never fires).
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).facilityId, 'fac-a1');
  assert.deepEqual(body.data.find((d) => d.id === TEM_A1_MON).staff, { id: STAFF_A1_DR, fullName: 'Aarav Sharma', designation: 'Cardiologist' });
  assert.deepEqual(body.data.find((d) => d.id === TEM_A1_SUN).staff, { id: STAFF_A1_NR, fullName: 'Bina Gurung', designation: 'Nurse' });
  assert.equal(body.data.find((d) => d.id === TEM_A1_NOSVC).serviceId, null);
  assert.deepEqual(body.data.find((d) => d.id === TEM_A1_NOSVC).staff, { id: STAFF_A1_GD, fullName: 'Dawa Sherpa', designation: null });
  // Time + date contract: H:i times (the datetime cast's format),
  // YYYY-MM-DD dates (validTo nullable).
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).startsAt, '09:00');
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).endsAt, '12:00');
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).validFrom, '2026-01-01');
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).validTo, '2026-12-31');
  assert.equal(body.data.find((d) => d.id === TEM_A1_TUE).validTo, null);
  // dayOfWeek ∈ 0..6 (ISO 8601), slot/capacity integers.
  assert.deepEqual(body.data.map((d) => d.dayOfWeek), [0, 1, 2, 4, 4]);
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).slotMinutes, 30);
  assert.equal(body.data.find((d) => d.id === TEM_A1_MON).capacity, 2);
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...SCHEDULE_TEMPLATE_ITEM_KEYS]);
    if (item.staff !== null) {
      assert.deepEqual(Object.keys(item.staff).sort(), [...SCHEDULE_TEMPLATE_STAFF_REF_KEYS]);
    }
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:schedule-templates — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  // The DOCTOR is the facility-scoped success actor — the seeded doctor
  // role holds schedule:view (RolePermissionSeeder doctor block).
  const deps = scheduleTemplatesFixtureDeps();
  const response = await scheduleTemplatesAs(SUB_FAC_DOCTOR, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  assert.deepEqual(body.data.map((d) => d.dayOfWeek), [0, 1, 2, 4]);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === TEM_A2_THU), false);
  assert.equal(body.data.some((d) => d.id === TEM_B_SUN), false);
  assert.equal(body.data.some((d) => d.id === TEM_A1_DEL), false);
});

await test('organizations:schedule-templates — a branch proposal does NOT narrow the read (schedule_templates is TENANT_FACILITY, no branch dimension)', async () => {
  // schedule_templates has NO branch_id column — the TENANT_FACILITY select
  // policy is `tenant_id = TENANT AND (facility_id = FACILITY OR FACILITY
  // IS NULL)` with NO branch clause. The validated X-Swasthya-Branch
  // proposal is accepted (context validation) but is irrelevant to the
  // read: every fac-a1 row stays visible.
  const deps = scheduleTemplatesFixtureDeps();
  const response = await scheduleTemplatesAs(SUB_FAC_DOCTOR, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.dayOfWeek), [0, 1, 2, 4]);
  assert.equal(body.data.some((d) => d.id === TEM_A2_THU), false);
  assert.equal(body.data.some((d) => d.id === TEM_B_SUN), false);
});

await test('organizations:schedule-templates — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = scheduleTemplatesFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsScheduleTemplates(
    scheduleTemplatesReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAScheduleTemplatesOrdered);
  assert.equal(body.data.some((d) => d.id === TEM_B_SUN), false);
});

await test('organizations:schedule-templates — empty organization has an empty list (bare array, never null)', async () => {
  const deps = scheduleTemplatesFixtureDeps({ templates: [] });
  const response = await scheduleTemplatesAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:schedule-templates — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = scheduleTemplatesFixtureDeps();
  const before = deps.getScheduleTemplates().map((t) => ({ id: t.id, dayOfWeek: t.dayOfWeek, status: t.status, deletedAt: t.deletedAt }));
  const response = await scheduleTemplatesAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getScheduleTemplates().map((t) => ({ id: t.id, dayOfWeek: t.dayOfWeek, status: t.status, deletedAt: t.deletedAt }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:schedule-templates — correlation id is echoed', async () => {
  const deps = scheduleTemplatesFixtureDeps();
  const response = await scheduleTemplatesAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase43' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase43');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase43');
});

/* ------------------------------------------------------------------ */
/* Phase 44 — organizations:schedule-exceptions                         */
/* ------------------------------------------------------------------ */

function scheduleExceptionsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-schedule-exceptions/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function scheduleExceptionsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsScheduleExceptions(
    scheduleExceptionsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('organizations:schedule-exceptions — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsScheduleExceptions(scheduleExceptionsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:schedule-exceptions — the gate is schedule:view, DISTINCT from the related reads', async () => {
  // The billing_clerk holds patient/insurance/payer/appointment/queue/
  // encounter/billing view permissions — but NOT schedule:view (the
  // seeded billing_clerk role lacks it — RolePermissionSeeder billing_clerk
  // block has no schedule:view). The SAME gate as the Phase 43 templates
  // read (ScheduleController::exceptions route gate
  // `authorize:schedule:view`). Gate distinctness. (The doctor + nurse
  // DO hold schedule:view — the seeded clinical roles read the schedule.)
  const response = await scheduleExceptionsAs(SUB_CASHIER, 'org-a', {}, scheduleExceptionsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:schedule-exceptions — malformed/nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await scheduleExceptionsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, scheduleExceptionsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:schedule-exceptions — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  const response = await scheduleExceptionsAs(SUB_TENANT_ADMIN, 'org-b', {}, scheduleExceptionsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:schedule-exceptions — org-level read: every facility of the tenant, ordered by exception_date DESC, no status filter', async () => {
  const deps = scheduleExceptionsFixtureDeps();
  const response = await scheduleExceptionsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  // The exact Laravel order (->orderByDesc('exception_date')); the
  // org-a/fac-a2 row IS visible to the org-level caller (no facility
  // filter); the org-b row is NOT.
  assert.deepEqual(body.data, orgAScheduleExceptionsOrdered);
  // NO status filter: the CANCELLED holiday row is present (status ∈
  // active/cancelled — the CHECK-constrained lifecycle statuses; NOT
  // soft-deletable — no deleted_at column, so there is NO soft-delete
  // exclusion to prove).
  assert.equal(body.data.find((d) => d.id === EXC_A1_HOL_CXL).status, 'cancelled');
  // Date + reason contract: YYYY-MM-DD exceptionDate (the date cast's
  // toDateString), reason ∈ leave/holiday/block (the CHECK constraint).
  assert.equal(body.data.find((d) => d.id === EXC_A1_LV).exceptionDate, '2026-03-05');
  assert.equal(body.data.find((d) => d.id === EXC_A1_LV).reason, 'leave');
  assert.equal(body.data.find((d) => d.id === EXC_A1_BLK).reason, 'block');
  assert.equal(body.data.find((d) => d.id === EXC_A1_HOL_CXL).reason, 'holiday');
  // The staff reference is NOT presented (presentException exposes NO
  // staff ref — the eager with('staff:id,full_name') is a query-level
  // detail only, unlike the templates read).
  assert.equal('staff' in body.data[0], false);
  // Exact key sets — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...SCHEDULE_EXCEPTION_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy', 'templateId']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:schedule-exceptions — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  // The DOCTOR is the facility-scoped success actor — the seeded doctor
  // role holds schedule:view (RolePermissionSeeder doctor block).
  const deps = scheduleExceptionsFixtureDeps();
  const response = await scheduleExceptionsAs(SUB_FAC_DOCTOR, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 3);
  assert.deepEqual(body.data.map((d) => d.exceptionDate), ['2026-04-01', '2026-03-05', '2026-02-14']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === EXC_A2_LV), false);
  assert.equal(body.data.some((d) => d.id === EXC_B_HOL), false);
});

await test('organizations:schedule-exceptions — a branch proposal does NOT narrow the read (schedule_exceptions is TENANT_FACILITY, no branch dimension)', async () => {
  // schedule_exceptions has NO branch_id column — the TENANT_FACILITY
  // select policy is `tenant_id = TENANT AND (facility_id = FACILITY OR
  // FACILITY IS NULL)` with NO branch clause. The validated
  // X-Swasthya-Branch proposal is accepted (context validation) but is
  // irrelevant to the read: every fac-a1 row stays visible.
  const deps = scheduleExceptionsFixtureDeps();
  const response = await scheduleExceptionsAs(SUB_FAC_DOCTOR, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.exceptionDate), ['2026-04-01', '2026-03-05', '2026-02-14']);
  assert.equal(body.data.some((d) => d.id === EXC_A2_LV), false);
  assert.equal(body.data.some((d) => d.id === EXC_B_HOL), false);
});

await test('organizations:schedule-exceptions — forged app_* claims in the token are ignored (context is the only source)', async () => {
  const deps = scheduleExceptionsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsScheduleExceptions(
    scheduleExceptionsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, orgAScheduleExceptionsOrdered);
  assert.equal(body.data.some((d) => d.id === EXC_B_HOL), false);
});

await test('organizations:schedule-exceptions — empty organization has an empty list (bare array, never null)', async () => {
  const deps = scheduleExceptionsFixtureDeps({ exceptions: [] });
  const response = await scheduleExceptionsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:schedule-exceptions — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = scheduleExceptionsFixtureDeps();
  const before = deps.getScheduleExceptions().map((e) => ({ id: e.id, exceptionDate: e.exceptionDate, reason: e.reason, status: e.status }));
  const response = await scheduleExceptionsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getScheduleExceptions().map((e) => ({ id: e.id, exceptionDate: e.exceptionDate, reason: e.reason, status: e.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:schedule-exceptions — correlation id is echoed', async () => {
  const deps = scheduleExceptionsFixtureDeps();
  const response = await scheduleExceptionsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase44' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase44');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase44');
});

/* ------------------------------------------------------------------ */
/* Phase 34 — facilities:branches                                       */
/* ------------------------------------------------------------------ */

function branchesReq(facilityId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/facilities-branches/${facilityId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function branchesAs(sub, facilityId, headers = {}, deps = makeDeps()) {
  return handleFacilitiesBranches(
    branchesReq(facilityId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// The fac-a1 read returns the EXACT 5-field present() items in name order
// (facilityId renders null — the literal Laravel index output).
const facA1BranchesOrdered = [
  { id: BR_A1_CARD, facilityId: null, name: 'Cardiology Clinic', code: 'CARD', status: 'active' },
  { id: BR_A1_ER, facilityId: null, name: 'Emergency Wing', code: 'ER', status: 'active' },
  { id: BR_A1_LAB, facilityId: null, name: 'Lab Services', code: 'LAB', status: 'inactive' },
];

await test('facilities:branches — unauthenticated request is 401', async () => {
  const response = await handleFacilitiesBranches(branchesReq('fac-a1'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('facilities:branches — the gate is branch:view, DISTINCT from the patient/insurance/consent/document/department reads', async () => {
  // The doctor holds patient:view + insurance:view + consent:view +
  // document:view + queue:view + encounter:view + billing:view — but NOT
  // branch:view (the seeded doctor role lacks it). Gate distinctness.
  const response = await branchesAs(SUB_FAC_DOCTOR, 'fac-a1', {}, branchesFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('facilities:branches — nonexistent facility is the DISTINCT 404 Facility not found.', async () => {
  // Any unknown selector resolves to null at the AccessCheck layer
  // (Facility::find($id)) — the 404 'Facility not found.', never 400/422.
  const response = await branchesAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, branchesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Facility not found.');
});

await test('facilities:branches — a facility outside the tenant is 404 (existence never leaked)', async () => {
  // The tenant admin belongs to org-a; fac-b is org-b's facility →
  // out-of-tenant → deny(read) → the generic 404.
  const response = await branchesAs(SUB_TENANT_ADMIN, 'fac-b', {}, branchesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('facilities:branches — a facility-scoped principal cannot read another facility (AccessCheck::facility)', async () => {
  // The hospital admin is scoped to fac-a1; fac-a2 exists in the same
  // tenant but is outside the facility scope → deny(read) → 404.
  const response = await branchesAs(SUB_HOSP_ADMIN, 'fac-a2', {}, branchesFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('facilities:branches — org-level read: the facility branches ordered by name ASC, no status filter', async () => {
  const deps = branchesFixtureDeps();
  const response = await branchesAs(SUB_TENANT_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 3);
  // The exact Laravel order (->orderBy('name')); the fac-a2 + org-b rows
  // are bound to OTHER facilities and never appear.
  assert.deepEqual(body.data, facA1BranchesOrdered);
  // NO status filter: the inactive Lab Services row is present.
  assert.equal(body.data.find((d) => d.id === BR_A1_LAB).status, 'inactive');
  // Exact key set — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...BRANCH_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'facilityId2', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('facilities:branches — facility-scoped read: exactly the caller facility', async () => {
  const deps = branchesFixtureDeps();
  const response = await branchesAs(SUB_HOSP_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, facA1BranchesOrdered);
  assert.equal(body.data.some((d) => d.id === BR_A2_SUR), false);
  assert.equal(body.data.some((d) => d.id === BR_B_ONC), false);
});

await test('facilities:branches — cross-facility / cross-tenant branches are bound to their own facility reads', async () => {
  // The org-level principal may read ANY in-tenant facility — fac-a2
  // returns exactly its own branch; fac-b is out-of-tenant (asserted
  // above).
  const deps = branchesFixtureDeps();
  const response = await branchesAs(SUB_TENANT_ADMIN, 'fac-a2', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, [
    { id: BR_A2_SUR, facilityId: null, name: 'Surgery Annex', code: 'SUR', status: 'active' },
  ]);
});

await test('facilities:branches — forged app_* claims in the token are ignored (context is the only source)', async () => {
  // A hostile token payload claims fac-b/org-b — the claims are derived
  // from the resolved context (org-a/fac-a1... org-level), so the read
  // returns the fac-a1 set, never a cross-tenant/facility view.
  const deps = branchesFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleFacilitiesBranches(
    branchesReq('fac-a1', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, facA1BranchesOrdered);
  assert.equal(body.data.some((d) => d.id === BR_B_ONC), false);
});

await test('facilities:branches — empty facility has an empty list (bare array, never null)', async () => {
  const deps = branchesFixtureDeps({ branches: [] });
  const response = await branchesAs(SUB_TENANT_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('facilities:branches — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = branchesFixtureDeps();
  const before = deps.getBranches().map((b) => ({ id: b.id, name: b.name, status: b.status }));
  const response = await branchesAs(SUB_TENANT_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getBranches().map((b) => ({ id: b.id, name: b.name, status: b.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('facilities:branches — correlation id is echoed', async () => {
  const deps = branchesFixtureDeps();
  const response = await branchesAs(SUB_TENANT_ADMIN, 'fac-a1', { 'X-Correlation-Id': 'corr-phase34' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase34');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase34');
});

/* ------------------------------------------------------------------ */
/* Phase 45 — facilities:settings                                       */
/* ------------------------------------------------------------------ */

function settingsReq(facilityId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/facilities-settings/${facilityId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function settingsAs(sub, facilityId, headers = {}, deps = makeDeps()) {
  return handleFacilitiesSettings(
    settingsReq(facilityId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

await test('facilities:settings — unauthenticated request is 401', async () => {
  const response = await handleFacilitiesSettings(settingsReq('fac-a1'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('facilities:settings — the gate is settings:view, DISTINCT from the related reads', async () => {
  // The doctor holds patient:view + insurance:view + consent:view +
  // document:view + schedule:view + queue:view + encounter:view +
  // billing:view + medication:view — but NOT settings:view (the seeded
  // doctor role lacks it — RolePermissionSeeder doctor block has no
  // settings:view). Gate distinctness. (The org_admin + hospital_admin DO
  // hold settings:view — the Phase 45 success actors.)
  const response = await settingsAs(SUB_FAC_DOCTOR, 'fac-a1', {}, settingsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('facilities:settings — nonexistent facility is the DISTINCT 404 Facility not found.', async () => {
  // Any unknown selector resolves to null at the AccessCheck layer
  // (Facility::find($id)) — the 404 'Facility not found.', never 400/422.
  const response = await settingsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, settingsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Facility not found.');
});

await test('facilities:settings — a facility outside the tenant is 404 (existence never leaked)', async () => {
  // The tenant admin belongs to org-a; fac-b is org-b's facility →
  // out-of-tenant → deny(read) → the generic 404.
  const response = await settingsAs(SUB_TENANT_ADMIN, 'fac-b', {}, settingsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('facilities:settings — a facility-scoped principal cannot read another facility (AccessCheck::facility)', async () => {
  // The hospital admin is scoped to fac-a1; fac-a2 exists in the same
  // tenant but is outside the facility scope → deny(read) → 404.
  const response = await settingsAs(SUB_HOSP_ADMIN, 'fac-a2', {}, settingsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('facilities:settings — org-level read: the facility settings as the keyed mapWithKeys OBJECT ordered by key ASC', async () => {
  const deps = settingsFixtureDeps();
  const response = await settingsAs(SUB_TENANT_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  // The exact mapWithKeys shape: a JSON OBJECT keyed by setting key
  // (never an array).
  assert.equal(Array.isArray(body.data), false);
  // The exact Laravel order (->orderBy('key')); the fac-a2 + org-b rows
  // are bound to OTHER facilities and never appear; NO status column, NO
  // soft-deletes — nothing is excluded.
  assert.deepEqual(body.data, facA1SettingsOrdered);
  assert.deepEqual(Object.keys(body.data), ['appointment.bufferMinutes', 'billing.defaultCurrency', 'clinic.name']);
  // The value/version/updatedAt entry contract: the decoded jsonb value
  // (the 'array' cast), the integer version counter, the toIso8601String
  // timestamp ('+00:00' offset) — and the NULL updatedAt for the
  // never-updated setting (the `?->` null guard).
  assert.deepEqual(body.data['appointment.bufferMinutes'], { value: { minutes: 10 }, version: 2, updatedAt: '2026-03-10T08:30:00+00:00' });
  assert.deepEqual(body.data['clinic.name'], { value: { displayName: 'Fac A1 Clinic' }, version: 3, updatedAt: '2026-04-01T12:00:00+00:00' });
  assert.deepEqual(body.data['billing.defaultCurrency'], { value: 'NPR', version: 1, updatedAt: null });
  // Exact entry key set — nothing else ever leaves the handler.
  for (const key of Object.keys(body.data)) {
    assert.deepEqual(Object.keys(body.data[key]).sort(), ['updatedAt', 'value', 'version']);
  }
  for (const forbidden of ['tenantId', 'facilityId', 'createdAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(Object.values(body.data).flatMap((e) => Object.keys(e)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('facilities:settings — facility-scoped read: exactly the caller facility', async () => {
  const deps = settingsFixtureDeps();
  const response = await settingsAs(SUB_HOSP_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, facA1SettingsOrdered);
  assert.equal('reception.tokens' in body.data, false);
  assert.equal('pharmacy.hours' in body.data, false);
});

await test('facilities:settings — cross-facility / cross-tenant settings are bound to their own facility reads', async () => {
  // The org-level principal may read ANY in-tenant facility — fac-a2
  // returns exactly its own settings; fac-b is out-of-tenant (asserted
  // above).
  const deps = settingsFixtureDeps();
  const response = await settingsAs(SUB_TENANT_ADMIN, 'fac-a2', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, {
    'reception.tokens': { value: { tokenPrefix: 'F2' }, version: 1, updatedAt: '2026-02-01T09:00:00+00:00' },
  });
});

await test('facilities:settings — forged app_* claims in the token are ignored (context is the only source)', async () => {
  // A hostile token payload claims fac-b/org-b — the claims are derived
  // from the resolved context (org-a/fac-a1... org-level), so the read
  // returns the fac-a1 set, never a cross-tenant/facility view.
  const deps = settingsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleFacilitiesSettings(
    settingsReq('fac-a1', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, facA1SettingsOrdered);
  assert.equal('pharmacy.hours' in body.data, false);
});

await test('facilities:settings — empty facility has an empty object (the keyed map is never null)', async () => {
  const deps = settingsFixtureDeps({ settings: [] });
  const response = await settingsAs(SUB_TENANT_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, {});
  assert.equal(Array.isArray(body.data), false);
});

await test('facilities:settings — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = settingsFixtureDeps();
  const before = deps.getSettings().map((s) => ({ key: s.key, value: s.value, version: s.version, updatedAt: s.updatedAt }));
  const response = await settingsAs(SUB_TENANT_ADMIN, 'fac-a1', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getSettings().map((s) => ({ key: s.key, value: s.value, version: s.version, updatedAt: s.updatedAt }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('facilities:settings — correlation id is echoed', async () => {
  const deps = settingsFixtureDeps();
  const response = await settingsAs(SUB_TENANT_ADMIN, 'fac-a1', { 'X-Correlation-Id': 'corr-phase45' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase45');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase45');
});

/* ------------------------------------------------------------------ */
/* Phase 33 — organizations:departments                                 */
/* ------------------------------------------------------------------ */

function departmentsReq(orgId, headers = {}) {
  return new Request(`https://example.supabase.co/functions/v1/organizations-departments/${orgId}`, {
    method: 'GET',
    headers: { ...headers },
  });
}

async function departmentsAs(sub, orgId, headers = {}, deps = makeDeps()) {
  return handleOrganizationsDepartments(
    departmentsReq(orgId, { Authorization: `Bearer ${await gotrueToken({ sub })}`, ...headers }),
    deps,
  );
}

// The org-scoped read returns the EXACT 7-field present() items (sorted
// alphabetically by id for comparison — the name order is asserted per
// test).
const orgAOrdered = [
  { id: DEPT_CARD, facilityId: 'fac-a1', branchId: 'br-a1', name: 'Cardiology', code: 'CARD', status: 'active', parentDepartmentId: null },
  { id: DEPT_ER, facilityId: 'fac-a1', branchId: 'br-a1', name: 'Emergency', code: 'ER', status: 'active', parentDepartmentId: null },
  { id: DEPT_LAB, facilityId: 'fac-a1', branchId: null, name: 'Laboratory', code: 'LAB', status: 'inactive', parentDepartmentId: DEPT_CARD },
  { id: DEPT_A1_OTHERBR, facilityId: 'fac-a1', branchId: 'br-a1b', name: 'Radiology', code: 'RAD', status: 'active', parentDepartmentId: null },
  { id: DEPT_SUR, facilityId: 'fac-a2', branchId: 'br-a2', name: 'Surgery', code: 'SUR', status: 'active', parentDepartmentId: null },
].sort((x, y) => (x.name < y.name ? -1 : 1));

await test('organizations:departments — unauthenticated request is 401', async () => {
  const response = await handleOrganizationsDepartments(departmentsReq('org-a'), makeDeps());
  assert.equal(response.status, 401);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'INVALID_TOKEN');
  assert.equal(body.error.message, 'Authentication required.');
});

await test('organizations:departments — the gate is department:view, DISTINCT from the patient/insurance/consent/document reads', async () => {
  // The doctor holds patient:view + insurance:view + consent:view +
  // document:view + queue:view + encounter:view + billing:view — but NOT
  // department:view (the seeded doctor role lacks it). Gate distinctness.
  const response = await departmentsAs(SUB_FAC_DOCTOR, 'org-a', {}, departmentsFixtureDeps());
  assert.equal(response.status, 403);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'SCOPE_DENIED');
  assert.equal(body.error.message, 'You are not authorized to perform this action.');
});

await test('organizations:departments — malformed/nonexistent organization id is 404 (never 400/422)', async () => {
  // Any unknown selector resolves to null at the AccessCheck layer
  // (Organization::find($id)) — the 404 'Organization not found.', never
  // 400/422; a KNOWN but out-of-scope organization is the deny(read) 404
  // 'Resource not found.' (asserted below).
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'not-a-uuid', {}, departmentsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:departments — nonexistent organization is the DISTINCT 404 Organization not found.', async () => {
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'aaaaaaaa-0000-4000-8000-00000000ffff', {}, departmentsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Organization not found.');
});

await test('organizations:departments — an organization outside the authoritative scope is 404 (existence never leaked)', async () => {
  // The tenant admin is assigned to org-a only; org-b exists but is out of
  // scope → deny(read) → the generic 404 (AccessCheck::organization).
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'org-b', {}, departmentsFixtureDeps());
  assert.equal(response.status, 404);
  const body = await bodyJson(response);
  assert.equal(body.error.code, 'NOT_FOUND');
  assert.equal(body.error.message, 'Resource not found.');
});

await test('organizations:departments — org-level read: every facility of the tenant, ordered by name ASC, no status filter', async () => {
  const deps = departmentsFixtureDeps();
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 5);
  // The exact Laravel order (->orderBy('name')): Cardiology, Emergency,
  // Laboratory, Radiology, Surgery — the org-a/fac-a2 row IS visible to
  // the org-level caller (no facility filter); the org-b row is NOT.
  assert.deepEqual(body.data.map((d) => d.name), ['Cardiology', 'Emergency', 'Laboratory', 'Radiology', 'Surgery']);
  assert.deepEqual(
    body.data.map((d) => ({ id: d.id, facilityId: d.facilityId, branchId: d.branchId, name: d.name, code: d.code, status: d.status, parentDepartmentId: d.parentDepartmentId })),
    orgAOrdered,
  );
  // NO status filter: the inactive Laboratory row is present.
  assert.equal(body.data.find((d) => d.id === DEPT_LAB).status, 'inactive');
  // Nullable contract: Laboratory has a NULL branch and a real parent;
  // the parent ref is present, never suppressed.
  assert.equal(body.data.find((d) => d.id === DEPT_LAB).branchId, null);
  assert.equal(body.data.find((d) => d.id === DEPT_LAB).parentDepartmentId, DEPT_CARD);
  // Exact key set — nothing else ever leaves the handler.
  for (const item of body.data) {
    assert.deepEqual(Object.keys(item).sort(), [...DEPARTMENT_ITEM_KEYS]);
  }
  for (const forbidden of ['tenantId', 'createdAt', 'updatedAt', 'deletedAt', 'createdBy', 'updatedBy']) {
    const allKeys = new Set(body.data.flatMap((d) => Object.keys(d)));
    assert.equal(allKeys.has(forbidden), false, `internal field ${forbidden} must never leak`);
  }
});

await test('organizations:departments — facility-scoped read: exactly the caller facility, cross-facility invisible', async () => {
  const deps = departmentsFixtureDeps();
  const response = await departmentsAs(SUB_HOSP_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.equal(body.data.length, 4);
  // The exact Laravel order; the fac-a2 Surgery row is invisible to fac-a1
  // claims (the facility filter), and the org-b row is invisible to org-a.
  // With NO branch claim the branch clause passes every branch of the
  // facility — the br-a1b Radiology row IS visible.
  assert.deepEqual(body.data.map((d) => d.name), ['Cardiology', 'Emergency', 'Laboratory', 'Radiology']);
  for (const item of body.data) {
    assert.equal(item.facilityId, 'fac-a1');
  }
  assert.equal(body.data.some((d) => d.id === DEPT_SUR), false);
  assert.equal(body.data.some((d) => d.id === DEPT_B_ONC), false);
});

await test('organizations:departments — branch scope narrows via the validated X-Swasthya-Branch proposal', async () => {
  // The hospital admin (fac-a1) proposes br-a1 — a branch of THEIR
  // facility → branch claim br-a1 → the TENANT_FACILITY_BRANCH branch
  // clause keeps br-a1 rows + branch-less rows, hides nothing else at
  // this scope (all fac-a1 rows are br-a1 or branch-less).
  const deps = departmentsFixtureDeps();
  const response = await departmentsAs(SUB_HOSP_ADMIN, 'org-a', { 'X-Swasthya-Branch': 'br-a1' }, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  // The br-a1 proposal is valid for the fac-a1 caller → the branch clause
  // `(branch_id IS NULL OR branch_id = BRANCH OR BRANCH IS NULL)` keeps
  // br-a1 rows + branch-less rows and HIDES the br-a1b Radiology row — the
  // wrong-branch-invisible proof.
  assert.deepEqual(body.data.map((d) => d.name), ['Cardiology', 'Emergency', 'Laboratory']);
  assert.equal(body.data.some((d) => d.id === DEPT_A1_OTHERBR), false);
  assert.equal(body.data.some((d) => d.id === DEPT_SUR), false);
  assert.equal(body.data.some((d) => d.id === DEPT_B_ONC), false);
});

await test('organizations:departments — forged app_* claims in the token are ignored (context is the only source)', async () => {
  // A hostile token payload claims org-b/another facility — the claims are
  // derived from the resolved context (org-a), so the read returns the
  // org-a set, never a cross-tenant/facility view.
  const deps = departmentsFixtureDeps();
  const token = await gotrueToken({ sub: SUB_TENANT_ADMIN, app_tenant_id: 'org-b', app_facility_id: 'fac-b', app_branch_id: 'br-b', app_is_platform: 'true' });
  const response = await handleOrganizationsDepartments(
    departmentsReq('org-a', { Authorization: `Bearer ${token}` }),
    deps,
  );
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data.map((d) => d.name), ['Cardiology', 'Emergency', 'Laboratory', 'Radiology', 'Surgery']);
  assert.equal(body.data.some((d) => d.id === DEPT_B_ONC), false);
});

await test('organizations:departments — empty organization has an empty list (bare array, never null)', async () => {
  const deps = departmentsFixtureDeps({ departments: [] });
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const body = await bodyJson(response);
  assert.deepEqual(body.data, []);
});

await test('organizations:departments — the read mutates nothing and audits nothing (zero rows changed)', async () => {
  const deps = departmentsFixtureDeps();
  const before = deps.getDepartments().map((d) => ({ id: d.id, name: d.name, status: d.status }));
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'org-a', {}, deps);
  assert.equal(response.status, 200);
  const after = deps.getDepartments().map((d) => ({ id: d.id, name: d.name, status: d.status }));
  assert.deepEqual(after, before);
  assert.equal(deps.getAuditEvents().length, 0);
});

await test('organizations:departments — correlation id is echoed', async () => {
  const deps = departmentsFixtureDeps();
  const response = await departmentsAs(SUB_TENANT_ADMIN, 'org-a', { 'X-Correlation-Id': 'corr-phase33' }, deps);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('X-Correlation-Id'), 'corr-phase33');
  assert.equal(response.headers.get('X-Request-Id'), 'corr-phase33');
});

/* ------------------------------------------------------------------ */

console.log(`\n${passed} passed, ${failed} failed`);
if (failed > 0) {
  console.error('\nFailures:');
  for (const { name, error } of failures) console.error(`  - ${name}: ${error?.message}`);
  process.exit(1);
}
