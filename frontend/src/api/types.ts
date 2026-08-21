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
  facilityId?: string;
  departmentId?: string;
  department?: { id: string; name: string } | null;
  fullName: string;
  employeeCode: string;
  designation: string | null;
  status: string;
  userId?: string;
  hireDate?: string | null;
  // Doctor profile fields
  specialty?: string | null;
  subSpecialty?: string | null;
  consultationFee?: number | null;
  consultationDurationMinutes?: number | null;
  bio?: string | null;
  acceptsNewPatients?: boolean;
  profileImageUrl?: string | null;
  availableDays?: number[] | null;
  consultationTypes?: string[] | null;
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
  departmentType: string;
  description: string | null;
  phone: string | null;
  location: string | null;
  operatingHours: Array<{ day: string; open: string; close: string }> | null;
  appointmentAvailability: Record<string, unknown> | null;
  queueSettings: Record<string, unknown> | null;
  responsibleRoles: string[] | null;
  sortOrder: number;
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

export interface HospitalBranding {
  id: string;
  tenantId: string;
  facilityId: string;
  hospitalName: string | null;
  hospitalNameLocal: string | null;
  logoUrl: string | null;
  faviconUrl: string | null;
  primaryColor: string | null;
  secondaryColor: string | null;
  phone: string | null;
  emergencyPhone: string | null;
  email: string | null;
  website: string | null;
  addressLine1: string | null;
  addressLine2: string | null;
  city: string | null;
  state: string | null;
  country: string | null;
  postalCode: string | null;
  documentHeader: string | null;
  documentFooter: string | null;
  letterheadText: string | null;
  dateFormat: string | null;
  timeFormat: string | null;
  currency: string | null;
  currencySymbol: string | null;
  vatRate: number | null;
  vatNumber: string | null;
  registrationNumber: string | null;
  panNumber: string | null;
  termsAndConditions: string | null;
  privacyPolicy: string | null;
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

/* ------------------------------------------------------------------
   Laboratory (DATABASE.md §3.26, PRODUCT_REQUIREMENTS §6.8)
   ------------------------------------------------------------------ */

export interface LabTest {
  id: string;
  facilityId: string;
  name: string;
  code: string;
  sampleType: string;
  resultType: string;
  unit: string | null;
  referenceRange: string | null;
  isCritical: boolean;
  turnaroundMinutes: number | null;
  priceMinor: number;
  currency: string;
  status: string;
}

export interface LabOrderItem {
  id: string;
  testId: string;
  testName: string | null;
  sampleType: string | null;
  resultValue: string | null;
  resultUnit: string | null;
  referenceRange: string | null;
  enteredAt: string | null;
  enteredByStaffId: string | null;
  verifiedAt: string | null;
  verifiedByStaffId: string | null;
  versions: LabResultVersion[];
}

export interface LabResultVersion {
  versionNo: number;
  resultValue: string;
  resultUnit: string | null;
  referenceRange: string | null;
  isCritical: boolean;
  correctionReason: string | null;
  enteredAt: string | null;
  enteredByStaffId: string | null;
  verifiedAt: string | null;
  verifiedByStaffId: string | null;
}

export interface Specimen {
  id: string;
  labOrderId: string;
  accessionNumber: string | null;
  specimenType: string;
  status: string;
  collectedAt: string | null;
  collectedByStaffId: string | null;
  accessionedAt: string | null;
  accessionedByStaffId: string | null;
  processedAt: string | null;
  processedByStaffId: string | null;
  completedAt: string | null;
  rejectedAt: string | null;
  rejectionReason: string | null;
}

export interface LabOrder {
  id: string;
  facilityId: string;
  patientId: string;
  encounterId: string;
  orderedByStaffId: string;
  priority: string;
  status: string;
  clinicalIndication: string | null;
  orderedAt: string | null;
  collectedAt: string | null;
  collectedByStaffId: string | null;
  processingAt: string | null;
  verifiedAt: string | null;
  verifiedByStaffId: string | null;
  reportedAt: string | null;
  reportedByStaffId: string | null;
  correctionReason: string | null;
  correctingAt: string | null;
  correctingByStaffId: string | null;
  lockVersion: number;
  specimens: Specimen[];
  items: LabOrderItem[];
}

export interface CriticalValueEvent {
  id: string;
  labOrderId: string;
  labOrderItemId: string;
  testId: string;
  testName: string | null;
  resultValue: string;
  referenceRange: string | null;
  severity: string;
  status: string;
  orderedByStaffId: string;
  acknowledgedByStaffId: string | null;
  acknowledgedAt: string | null;
  escalatedByStaffId: string | null;
  escalatedAt: string | null;
  escalationTarget: string | null;
  escalationReason: string | null;
  createdAt: string | null;
}

/* ------------------------------------------------------------------
   Radiology (DATABASE.md §3.29, PRODUCT_REQUIREMENTS §6.9)
   ------------------------------------------------------------------ */

export interface Modality {
  id: string;
  facilityId: string;
  name: string;
  modalityType: string;
  status: string;
  manufacturer: string | null;
  model: string | null;
}

export interface RadiologyStudy {
  id: string;
  labOrderId: string;
  facilityId: string;
  patientId: string;
  encounterId: string;
  modalityId: string | null;
  modality: { id: string; name: string } | null;
  priority: string;
  status: string;
  scheduledAt: string | null;
  performedAt: string | null;
  clinicalIndication: string | null;
  imageReferences: Array<{ url: string; description: string } | null>;
  lockVersion: number;
}

export interface RadiologyReport {
  id: string;
  studyId: string;
  facilityId: string;
  reportType: string;
  content: string;
  status: string;
  draftedAt: string | null;
  draftedByStaffId: string | null;
  verifiedAt: string | null;
  verifiedByStaffId: string | null;
  amendedAt: string | null;
  amendedByStaffId: string | null;
  lockVersion: number;
}

export interface RadiologyOrder {
  id: string;
  facilityId: string;
  patientId: string;
  encounterId: string;
  study: RadiologyStudy;
  status: string;
  orderedAt: string | null;
  priority: string;
  clinicalIndication: string | null;
}

/* ------------------------------------------------------------------
   Pharmacy (DATABASE.md §3.30–§3.31, PRODUCT_REQUIREMENTS §6.7)
   ------------------------------------------------------------------ */

export interface PharmacyMedication {
  id: string;
  genericName: string;
  brandName: string | null;
  strength: string;
  form: string;
  unit: string;
  isControlled: boolean;
  priceMinor: number;
  currency: string;
}

export interface PharmacyPrescriptionLine {
  id: string;
  medication: PharmacyMedication | null;
  dose: string;
  route: string;
  frequency: string;
  duration: string | null;
  quantityMinor: number | null;
  instructions: string | null;
  status: string;
  dispensedByStaffId: string | null;
  dispensedAt: string | null;
  availableQuantity: number | null;
  batchId: string | null;
  batchNumber: string | null;
  batchExpiresAt: string | null;
  batchQuantityMinor: number | null;
  dualVerifiedByStaffId: string | null;
  dualVerifiedAt: string | null;
}

export interface PharmacyPrescription {
  id: string;
  patientId: string;
  encounterId: string;
  prescriberStaffId: string;
  status: string;
  notes: string | null;
  verifiedByStaffId: string | null;
  verifiedAt: string | null;
  lockVersion: number;
  lines: PharmacyPrescriptionLine[];
}

/* ------------------------------------------------------------------
   Inventory (DATABASE.md §3.31, PRODUCT_REQUIREMENTS §6.14)
   ------------------------------------------------------------------ */

export interface InventoryItem {
  id: string;
  facilityId: string;
  medicationId: string;
  medication: PharmacyMedication | null;
  quantityOnHand: number;
  reorderLevel: number;
  lockVersion: number;
}

export interface StockBatch {
  id: string;
  inventoryItemId: string;
  medicationId: string;
  batchNumber: string;
  expiryDate: string | null;
  quantityReceived: number;
  quantityRemaining: number;
  status: string;
  controlledDispenseRequiresDual: boolean;
  expiryStatus: string;
  daysToExpiry: number | null;
}

export interface InventoryAdjustmentRequest {
  id: string;
  inventoryItemId: string;
  quantityDelta: number;
  status: string;
  requestedBy: string;
  approvedBy: string | null;
  approvedAt: string | null;
  rejectedBy: string | null;
  rejectedAt: string | null;
  lockVersion: number;
}

/* ------------------------------------------------------------------
   Procurement (ROADMAP §15, PRODUCT_REQUIREMENTS §6.15–§6.16)
   ------------------------------------------------------------------ */

export interface Vendor {
  id: string;
  facilityId: string;
  code: string;
  name: string;
  status: string;
  hasTaxId: boolean;
  hasBankDetails: boolean;
}

export interface PurchaseRequestLine {
  id: string;
  medicationId: string;
  quantity: number;
  estimatedUnitPriceMinor: number;
}

export interface PurchaseRequest {
  id: string;
  facilityId: string;
  requestNumber: string;
  status: string;
  requestedAt: string | null;
  estimatedTotalMinor: number;
  approval: {
    approverId: string;
    decision: string;
    decidedAt: string | null;
  } | null;
  lines: PurchaseRequestLine[];
}

export interface PurchaseOrderLine {
  id: string;
  medicationId: string;
  quantityOrdered: number;
  unitPriceMinor: number;
  receivedQuantity: number;
}

export interface PurchaseOrder {
  id: string;
  facilityId: string;
  poNumber: string;
  vendorId: string;
  status: string;
  expectedDelivery: string | null;
  lockVersion: number;
  lines: PurchaseOrderLine[];
}

/* ------------------------------------------------------------------
   Finance (PRODUCT_REQUIREMENTS §6.13–§6.14)
   ------------------------------------------------------------------ */

export interface Deposit {
  id: string;
  patientId: string;
  amountMinor: number;
  remainingMinor: number;
  status: string;
  collectedAt: string | null;
  lockVersion: number;
}

export interface AgingEntry {
  patientId: string;
  patientName: string | null;
  totalOutstandingMinor: number;
  buckets: {
    current: number;
    days30: number;
    days60: number;
    days90: number;
    over90: number;
  };
}

export interface Settlement {
  id: string;
  cashierId: string;
  settlementDate: string;
  expectedMinor: number;
  actualMinor: number;
  varianceMinor: number;
  status: string;
  reconciledAt: string | null;
  notes: string | null;
  lockVersion: number;
}

export interface InsuranceClaim {
  id: string;
  claimNumber: string;
  invoiceId: string;
  policyId: string;
  payerId: string;
  status: string;
  submittedAt: string | null;
  denialReason: string | null;
  settlementMinor: number;
  billedMinor: number;
  lockVersion: number;
  lines: Array<{
    id: string;
    invoiceLineId: string;
    billedMinor: number;
    approvedMinor: number;
    status: string;
  }>;
}

/* ------------------------------------------------------------------
   Analytics & Reporting (ROADMAP Phase 17, PRODUCT_REQUIREMENTS §6.17)
   ------------------------------------------------------------------ */

export interface KpiDefinition {
  id: string;
  code: string;
  name: string;
  domain: string;
  sourceTable: string;
  dateColumn: string;
  filter: string | null;
  aggregation: string;
  sumColumn: string | null;
  unit: string | null;
  version: number;
  status: string;
}

export interface KpiMetric {
  kpiId: string;
  facilityId: string | null;
  periodStart: string;
  periodEnd: string;
  value: number;
  computedAt: string;
}

export interface Dashboard {
  id: string;
  code: string;
  name: string;
  roleGate: string | null;
  isActive: boolean;
}

export interface ReportTemplate {
  id: string;
  code: string;
  name: string;
  category: string;
  scope: string;
  parameterSchema: Record<string, unknown> | null;
  query: string | null;
  isActive: boolean;
}

export interface ReportRun {
  id: string;
  templateId: string | null;
  templateCode: string | null;
  scheduleId: string | null;
  status: string;
  runAt: string;
  completedAt: string | null;
  rowCount: number | null;
  errorMessage: string | null;
  isExport: boolean;
  exportFormat: string | null;
  outputChecksum: string | null;
  rows?: Record<string, unknown>[];
}

export interface GeneratedDocument {
  id: string;
  documentNumber: string;
  documentType: string;
  category: string;
  title: string;
  sourceType: string | null;
  sourceId: string | null;
  patientId: string | null;
  patientName: string | null;
  patientMrn: string | null;
  providerName: string | null;
  departmentName: string | null;
  status: string;
  verified: boolean;
  verifiedAt: string | null;
  signed: boolean;
  signedAt: string | null;
  printable: boolean;
  pdfCapable: boolean;
  hasPdf: boolean;
  pageCount: number | null;
  visibility: string;
  sharedWithPatient: boolean;
  sharedAt: string | null;
  contentHtml?: string;
  brandingSnapshot?: Record<string, unknown>;
  metadata?: Record<string, unknown>;
  createdAt: string;
  updatedAt: string;
}
