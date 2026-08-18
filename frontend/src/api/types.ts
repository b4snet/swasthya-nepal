/**
 * API types — mirror of the backend envelope and entity presentations
 * (API_CONTRACTS.md §7–§8). The SPA never invents fields; anything missing
 * here is surfaced by the typecheck rather than silently assumed.
 */

export interface ApiMetaContext {
  tenantId: string | null;
  facilityId: string | null;
  branchId: string | null;
  timezone: string;
}

export interface ApiMeta {
  context: ApiMetaContext;
  duplicates?: unknown[];
  [key: string]: unknown;
}

export interface ApiSuccess<T> {
  data: T;
  meta: ApiMeta;
}

export interface ApiErrorBody {
  error: {
    code: string;
    message: string;
    correlationId: string;
    details?: Record<string, unknown>;
  };
}

export interface Assignment {
  organizationId: string | null;
  organizationCode: string | null;
  facilityId: string | null;
  facilityName: string | null;
  roles: string[];
}

export interface SessionUser {
  id: string;
  email: string;
  status: string;
}

export interface LoginResponse {
  accessToken: string;
  tokenType: string;
  expiresIn: number;
  refreshToken: string;
  refreshExpiresIn: number;
  user: SessionUser;
  assignments: Assignment[];
}

export interface Patient {
  id: string;
  mrn: string;
  facilityId: string;
  fullName: string;
  dateOfBirth: string;
  sex: 'male' | 'female' | 'other' | 'unknown';
  bloodGroup: string | null;
  status: string;
  createdAt: string;
  updatedAt: string;
}

export interface PatientListItem {
  id: string;
  mrn: string;
  fullName: string;
  dateOfBirth: string;
  sex: string;
  status: string;
}

export interface Appointment {
  id: string;
  facilityId: string;
  patientId: string;
  patient: { id: string; mrn: string; fullName: string } | null;
  providerStaffId: string;
  provider: { id: string; fullName: string } | null;
  serviceId: string | null;
  appointmentType: string;
  startsAt: string;
  endsAt: string;
  status: 'booked' | 'checked_in' | 'in_consultation' | 'completed' | 'cancelled' | 'no_show';
  tokenNo: number | null;
  source: string;
  cancelReason: string | null;
  lockVersion: number;
}

/**
 * An entry in the appointment queue (GET /appointments/queue). Distinct from
 * the full Appointment presentation: the queue contract uses `appointmentId`
 * and carries only the fields the queue/check-in views need.
 */
export interface QueueEntry {
  appointmentId: string;
  tokenNo: number | null;
  status: 'checked_in' | 'in_consultation';
  patient: { id: string; mrn: string; fullName: string } | null;
  startsAt: string;
  encounterId: string | null;
}

export interface AvailabilitySlot {
  startsAt: string;
  endsAt: string;
  templateId: string;
  capacity: number;
  booked: number;
  available: boolean;
}

export interface Encounter {
  id: string;
  tenantId: string;
  facilityId: string;
  patientId: string;
  patient: { id: string; mrn: string; fullName: string } | null;
  appointmentId: string | null;
  providerStaffId: string;
  provider: { id: string; fullName: string } | null;
  type: string;
  status: 'open' | 'in_progress' | 'signed' | 'amended' | 'closed';
  startedAt: string;
  signedAt: string | null;
  lockVersion: number;
}

export interface ClinicalNote {
  id: string;
  noteType: string;
  author: { id: string; fullName: string };
  content: Record<string, string>;
  status: 'draft' | 'signed';
  signedAt: string | null;
  lockVersion: number;
}

export interface Diagnosis {
  id: string;
  code: string | null;
  codingSystem: string | null;
  description: string;
  diagnosisType: string;
  isPrimary: boolean;
  status: string;
}

export interface PrescriptionLine {
  id: string;
  medication: { id: string; genericName: string; brandName: string | null; strength: string } | null;
  dose: string;
  route: string;
  frequency: string;
  duration: string | null;
  quantityMinor: number | null;
  instructions: string | null;
  status: string;
}

export interface Prescription {
  id: string;
  status: string;
  lineCount: number;
  lines: PrescriptionLine[];
}

export interface Charge {
  id: string;
  description: string;
  amountMinor: number;
  status: string;
  sourceType: string;
}

export interface Invoice {
  id: string;
  invoiceNumber: string;
  patientId: string;
  status: 'draft' | 'issued' | 'partially_paid' | 'paid' | 'voided';
  totalMinor: number;
  totalTaxMinor: number;
  paidMinor: number;
  issuedAt: string | null;
  lockVersion: number;
  lines?: InvoiceLine[];
}

export interface InvoiceLine {
  id: string;
  description: string;
  amountMinor: number;
  taxMinor: number;
  lineNo: number;
}

export interface Payment {
  id: string;
  method: string;
  amountMinor: number;
  status: string;
  providerRef: string | null;
  receivedAt: string;
}

export interface Staff {
  id: string;
  fullName: string;
  employeeCode: string;
  designation: string | null;
  status: string;
}

export interface Service {
  id: string;
  name: string;
  code: string;
  serviceType: string;
  defaultDurationMinutes: number | null;
  defaultChargeMinor: number | null;
  currency: string | null;
  status: string;
}

export interface Medication {
  id: string;
  code: string;
  genericName: string;
  brandName: string | null;
  strength: string;
  form: string;
  unit: string;
  priceMinor: number;
  currency: string;
  isControlled: boolean;
  status: string;
}

/**
 * A patient-timeline entry. `summary` is backend-structured metadata (a
 * string, or an object such as `{ mrn: 'MRN-…' }` or `{ changed: [...] }`) —
 * render it through the human-readable formatter in the UI, never directly.
 */
export interface TimelineEntry {
  id: string;
  occurredAt: string;
  eventType: string;
  summary: string | Record<string, unknown> | unknown[] | null;
}

export interface AuditEvent {
  id: string;
  action: string;
  entityType: string;
  entityId: string | null;
  actor: { id: string; email: string } | null;
  facilityId: string | null;
  occurredAt: string;
  metadata: Record<string, unknown> | null;
}

/* ------------------------------------------------------------------
   Admin module types (mirrors backend API_CONTRACTS §21)
   ------------------------------------------------------------------ */

export interface Organization {
  id: string;
  code: string;
  name: string;
  status: string;
  currency?: string;
  timezone?: string;
  locale?: string;
  settings?: Record<string, unknown>;
  facilities?: Array<{ id: string; name: string; code: string } | undefined>;
}

export interface Facility {
  id: string;
  name: string;
  code: string;
  status: string;
  timezone?: string;
  address?: Record<string, unknown> | null;
}

export interface AdminUser {
  id: string;
  email: string;
  status: string;
  assignments: Array<{
    organizationId: string;
    facilityId: string | null;
    role: string;
    status: string;
  }>;
}

export interface Role {
  id: string;
  code: string;
  name: string;
  scopeType: string;
  permissions: Array<{ id: string; code: string }>;
}

export interface Permission {
  id: string;
  code: string;
  domain: string;
  description: string | null;
}

export interface AdminStaff {
  id: string;
  facilityId: string;
  departmentId: string | null;
  department: { id: string; code: string; name: string } | null;
  employeeCode: string;
  fullName: string;
  designation: string | null;
  status: string;
  userId: string | null;
  hireDate: string | null;
}

export interface Department {
  id: string;
  facilityId: string;
  branchId: string | null;
  name: string;
  code: string;
  status: string;
  parentDepartmentId: string | null;
}

export interface AdminService {
  id: string;
  facilityId: string;
  departmentId: string | null;
  department: { id: string; code: string; name: string } | null;
  name: string;
  code: string;
  serviceType: string;
  status: string;
  defaultDurationMinutes: number | null;
  defaultChargeMinor: number | null;
  currency: string | null;
}

export interface FacilitySetting {
  value: string | number | boolean | null;
  version: number;
  updatedAt: string | null;
}

export interface PatientIdentifier {
  id: string;
  type: string;
  value: string;
  issuingCountry: string | null;
  isVerified: boolean;
  status: string;
}

export interface PatientContact {
  id: string;
  type: string;
  value: string;
  isPrimary: boolean;
  status: string;
}

export interface DuplicateCandidate {
  id: string;
  mrn: string;
  fullName: string;
  dateOfBirth: string;
  sex: string;
  matchReason: string;
}

/* ------------------------------------------------------------------
   Follow-up types (DATABASE.md §3.17, PRODUCT_REQUIREMENTS §6.7)
   ------------------------------------------------------------------ */

export interface FollowUp {
  id: string;
  patientId: string;
  encounterId: string;
  providerStaffId: string;
  followUpType: 'return_visit' | 'teleconsult';
  plannedAt: string | null;
  reason: string | null;
  bookedAppointmentId: string | null;
  status: 'planned' | 'booked' | 'completed' | 'cancelled';
  cancelReason: string | null;
  lockVersion: number;
}

export interface FollowUpReminder {
  id: string;
  followUpId: string;
  patientId: string;
  type: string;
  channel: string;
  status: string;
  sensitive: boolean;
  payload: Record<string, unknown>;
  createdAt: string | null;
}
