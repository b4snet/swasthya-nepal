// Auto-generated barrel re-export. Domain implementations live in ./<domain>.ts
// All existing imports from './endpoints' continue to work unchanged.

export * from './auth';
export * from './patients';
export * from './clinical';
export * from './inpatient';
export * from './pharmacy';
export * from './laboratory';
export * from './finance';
export * from './hr';
export * from './admin';
export * from './analytics';
export * from './communications';
export * from './oncology';
export * from './specialty';
export * from './documents';
export * from './bloodbank';
export * from './audit';
export * from './catalogs';

// Re-export types that were previously co-located here
export type {
  Appointment, Assignment, AuditEvent, AvailabilitySlot, ClinicalNote,
  CriticalValueEvent, Dashboard, Diagnosis, Encounter, FollowUp,
  FollowUpReminder, HospitalBranding, InventoryAdjustmentRequest,
  InventoryItem, Invoice, KpiDefinition, KpiMetric, LabOrder, LabTest,
  LoginResponse, Medication, Modality, Patient, PatientContact,
  PatientIdentifier, PatientListItem, PharmacyPrescription, PurchaseOrder,
  PurchaseRequest, QueueEntry, RadiologyOrder, RadiologyStudy, ReportRun,
  ReportTemplate, Service, Settlement, Staff, StockBatch, TimelineEntry,
  Vendor, Deposit, AgingEntry, GeneratedDocument, Position, ShiftTemplate,
  Roster, AttendanceRecord, LeaveType, LeaveRequest, PayrollExport,
  AssetCategory, Asset, AssetTransfer, MaintenanceSchedule, WorkOrder,
  Referral,
} from './types';