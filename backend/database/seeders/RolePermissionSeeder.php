<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * The platform RBAC catalog (MASTER_RULES.md §9.1, DATABASE.md §3.5–3.6).
 *
 * Roles are FIXED and seeded, never invented ad hoc (MASTER_RULES.md §9.1).
 * The full catalog is seeded now as definitions; each phase grants the
 * domain permissions its workflows need. A role with an empty permission set
 * is honest: its permissions arrive with its module's phase.
 *
 * Permissions are namespaced 'domain:action' and are part of the versioned
 * API contract (MASTER_RULES.md §9.5: a permission that cannot be tested is
 * not added — every permission seeded here is exercised by the authorization
 * matrix test suite).
 *
 * Idempotent: safe to re-run on any environment.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * The permission catalog for this phase (identity and tenancy).
     * Domain permissions (clinical, financial, …) are additive as their
     * modules ship — permissions are never removed, only retired with
     * migration review (DATABASE.md §3.6).
     *
     * @return array<string, array{domain: string, description: string}>
     */
    public static function permissionCatalog(): array
    {
        return [
            'organization:view' => ['domain' => 'organization', 'description' => 'View organization details and settings'],
            'organization:manage' => ['domain' => 'organization', 'description' => 'Create and administer organizations (platform provisioning)'],
            'facility:view' => ['domain' => 'facility', 'description' => 'View facilities within scope'],
            'facility:create' => ['domain' => 'facility', 'description' => 'Create facilities within the organization'],
            'user:view' => ['domain' => 'user', 'description' => 'View users and their assignments within scope'],
            'user:create' => ['domain' => 'user', 'description' => 'Create users within the organization'],
            'role:view' => ['domain' => 'role', 'description' => 'View the role catalog'],
            'role:assign' => ['domain' => 'role', 'description' => 'Grant role assignments within scope'],
            'role:revoke' => ['domain' => 'role', 'description' => 'Revoke role assignments within scope'],
            'audit:view' => ['domain' => 'audit', 'description' => 'View the audit trail within scope'],

            // Tenancy V2 — branch administration (TENANCY.md V2 §4).
            'branch:view' => ['domain' => 'branch', 'description' => 'View branches within scope'],
            'branch:manage' => ['domain' => 'branch', 'description' => 'Create, update, and deactivate branches'],

            // Tenancy V2 — platform support administration (TENANCY.md V2 §8):
            // the ONLY route to tenant data for platform staff, via explicit
            // audited, time-limited support sessions.
            'support:manage' => ['domain' => 'platform', 'description' => 'Open, list, and end support sessions (platform scope)'],

            // Phase 4 — Hospital Administration (facility catalogs).
            'department:view' => ['domain' => 'department', 'description' => 'View departments within scope'],
            'department:manage' => ['domain' => 'department', 'description' => 'Create, update, and deactivate departments'],
            'location:view' => ['domain' => 'location', 'description' => 'View locations within scope'],
            'location:manage' => ['domain' => 'location', 'description' => 'Create, update, and deactivate locations'],
            'ward:view' => ['domain' => 'ward', 'description' => 'View wards within scope'],
            'ward:manage' => ['domain' => 'ward', 'description' => 'Create, update, and deactivate wards'],
            'room:view' => ['domain' => 'room', 'description' => 'View rooms within scope'],
            'room:manage' => ['domain' => 'room', 'description' => 'Create, update, and deactivate rooms (incl. rates)'],
            'bed:view' => ['domain' => 'bed', 'description' => 'View beds and bed states within scope'],
            'bed:manage' => ['domain' => 'bed', 'description' => 'Manage beds and bed state transitions'],
            'staff:view' => ['domain' => 'staff', 'description' => 'View staff profiles within scope'],
            'staff:manage' => ['domain' => 'staff', 'description' => 'Create and update staff profiles'],
            'service:view' => ['domain' => 'service', 'description' => 'View the service catalog within scope'],
            'service:manage' => ['domain' => 'service', 'description' => 'Create, update, and deactivate services'],
            'settings:view' => ['domain' => 'settings', 'description' => 'View facility configuration'],
            'settings:manage' => ['domain' => 'settings', 'description' => 'Change facility configuration (versioned and audited)'],

            // Phase 5 — Patient Master.
            'patient:view' => ['domain' => 'patient', 'description' => 'View patient records within scope'],
            'patient:register' => ['domain' => 'patient', 'description' => 'Register new patients (issues MRN)'],
            'patient:update' => ['domain' => 'patient', 'description' => 'Update patient demographics, contacts, and identifiers'],
            'patient:search' => ['domain' => 'patient', 'description' => 'Search the patient index'],
            'patient:merge' => ['domain' => 'patient', 'description' => 'Merge duplicate patient records (high-risk identity resolution)'],
            'insurance:view' => ['domain' => 'insurance', 'description' => 'View patient insurance policies'],
            'insurance:manage' => ['domain' => 'insurance', 'description' => 'Create, update, and cancel insurance policies'],
            'consent:view' => ['domain' => 'consent', 'description' => 'View patient consent records'],
            'consent:manage' => ['domain' => 'consent', 'description' => 'Capture and revoke patient consents'],
            'document:view' => ['domain' => 'document', 'description' => 'View patient documents'],
            'document:manage' => ['domain' => 'document', 'description' => 'Upload and manage patient documents'],
            'payer:view' => ['domain' => 'payer', 'description' => 'View the payer catalog'],
            'payer:manage' => ['domain' => 'payer', 'description' => 'Create and update payers in the catalog'],

            // Phase 6 — Front Desk (schedules, bookings, queues).
            'schedule:view' => ['domain' => 'schedule', 'description' => 'View schedules, exceptions, and derived availability'],
            'schedule:manage' => ['domain' => 'schedule', 'description' => 'Create and manage provider schedules and exceptions'],
            'appointment:view' => ['domain' => 'appointment', 'description' => 'View appointments within scope'],
            'appointment:book' => ['domain' => 'appointment', 'description' => 'Book appointments (validated against availability)'],
            'appointment:checkin' => ['domain' => 'appointment', 'description' => 'Check patients in and issue queue tokens'],
            'appointment:cancel' => ['domain' => 'appointment', 'description' => 'Cancel appointments with a captured reason'],
            'queue:view' => ['domain' => 'queue', 'description' => 'View the live queue for a provider/date'],

            // Phase 7 — OPD (the clinical spine).
            'encounter:view' => ['domain' => 'encounter', 'description' => 'View encounters and their clinical content'],
            'encounter:create' => ['domain' => 'encounter', 'description' => 'Start an encounter from a checked-in appointment'],
            'encounter:document' => ['domain' => 'encounter', 'description' => 'Add clinical notes and diagnoses to an open encounter'],
            'encounter:prescribe' => ['domain' => 'encounter', 'description' => 'Write prescriptions on an open encounter'],
            'encounter:sign' => ['domain' => 'encounter', 'description' => 'Sign encounters and notes (immutable clinical sign-off)'],
            'medication:view' => ['domain' => 'medication', 'description' => 'View the formulary within scope'],
            'medication:manage' => ['domain' => 'medication', 'description' => 'Create and update formulary entries'],

            // Phase 3 slice 2 — Laboratory & radiology order lifecycle
            // (PRODUCT_REQUIREMENTS §6.8). Verification is a distinct
            // permission from entry — entry ≠ verification is a clinical
            // safety rule, enforced by both the permission split and a
            // different-staff guard in the controller.
            'lab:view' => ['domain' => 'lab', 'description' => 'View laboratory and radiology orders, results, and the test catalog within scope'],
            'lab:order' => ['domain' => 'lab', 'description' => 'Order laboratory/radiology tests from an encounter'],
            'lab:specimen' => ['domain' => 'lab', 'description' => 'Collect and accession specimens for lab orders'],
            'lab:process' => ['domain' => 'lab', 'description' => 'Mark lab orders as processing'],
            'lab:result_entry' => ['domain' => 'lab', 'description' => 'Enter laboratory results (entry, never verification)'],
            'lab:verify' => ['domain' => 'lab', 'description' => 'Verify laboratory results before release (distinct from entry)'],
            'lab:report' => ['domain' => 'lab', 'description' => 'Release final laboratory/radiology reports'],
            // Phase 3 slice 15 — corrected result versions (CLINICAL_SAFETY
            // §7): opening a reported (immutable) order for correction is the
            // lab quality gate — granted with lab:report authority, never to
            // the entry technician.
            'lab:correct' => ['domain' => 'lab', 'description' => 'Open a correction on a reported laboratory order (captured reason, new audited versions)'],
            'lab:manage' => ['domain' => 'lab', 'description' => 'Manage the lab/radiology test catalog'],

            // Phase 3 slice 7 — critical/panic value escalation
            // (PRODUCT_REQUIREMENTS §6.8 workflow 6, CLINICAL_SAFETY §7):
            // the ordering clinician ACKNOWLEDGES their critical value
            // (who/when recorded); a supervisor ESCALATES an unacknowledged
            // one — fail loudly, never silently. The escalate route also
            // refuses the target clinician (they acknowledge, not escalate).
            'lab:acknowledge' => ['domain' => 'lab', 'description' => 'Acknowledge a critical/panic laboratory value (the ordering clinician)'],
            'lab:escalate' => ['domain' => 'lab', 'description' => 'Escalate an unacknowledged critical/panic laboratory value (supervisor)'],

            // Phase 3 slice 16 — Radiology (PRODUCT_REQUIREMENTS §6.9,
            // CLINICAL_SAFETY §8). Report verification is a distinct
            // permission from drafting (entry ≠ verification, same
            // discipline as lab) and the different-staff guard is enforced
            // in the service.
            'radiology:view' => ['domain' => 'radiology', 'description' => 'View radiology orders, studies, reports, and the modality catalog within scope'],
            'radiology:order' => ['domain' => 'radiology', 'description' => 'Order imaging studies from an encounter'],
            'radiology:schedule' => ['domain' => 'radiology', 'description' => 'Schedule studies on modalities (modality capacity and slots)'],
            'radiology:perform' => ['domain' => 'radiology', 'description' => 'Perform imaging studies (radiographer)'],
            'radiology:report' => ['domain' => 'radiology', 'description' => 'Draft and amend radiology reports (entry, never verification)'],
            'radiology:verify' => ['domain' => 'radiology', 'description' => 'Verify radiology reports before release (distinct from drafting)'],
            'radiology:manage' => ['domain' => 'radiology', 'description' => 'Manage the modality catalog and radiology configuration'],

            // Phase 3 slice 3 — pharmacy dispensing & inventory
            // (PRODUCT_REQUIREMENTS §6.9). Verification and dispensing are
            // the same pharmacist workflow (unlike lab's entry ≠
            // verification, which is a laboratory-specific safety rule).
            'pharmacy:view' => ['domain' => 'pharmacy', 'description' => 'View prescriptions, dispensing status, and inventory within scope'],
            'pharmacy:dispense' => ['domain' => 'pharmacy', 'description' => 'Verify and dispense prescriptions (stock deduction, audited)'],
            'pharmacy:stock' => ['domain' => 'pharmacy', 'description' => 'Manage pharmacy inventory (receipts and adjustments)'],

            // Phase 3 slice 8 — pharmacy returns & reversals
            // (PRODUCT_REQUIREMENTS §6.7): the pharmacist reverses a dispensed
            // line — reason captured, stock restored, refund path opened.
            // Like dispensing, this is the pharmacist's clinical act, so the
            // permission is granted to the pharmacist role only.
            'pharmacy:return' => ['domain' => 'pharmacy', 'description' => 'Return or reverse a dispensed prescription line (stock restoration, audited)'],

            // Phase 3 slice 4 — discharge & follow-up (PRODUCT_REQUIREMENTS
            // §6.7): planned return visits linked to the encounter.
            'followup:view' => ['domain' => 'followup', 'description' => 'View follow-up plans within scope'],
            'followup:manage' => ['domain' => 'followup', 'description' => 'Plan, book, cancel, and complete follow-up visits'],

            // Billing and payments (Phase 13 spine, shipped with the
            // first clinical workflow).
            'billing:view' => ['domain' => 'billing', 'description' => 'View charges, invoices, and payments within scope'],
            'billing:invoice' => ['domain' => 'billing', 'description' => 'Issue invoices from posted charges'],
            'billing:collect' => ['domain' => 'billing', 'description' => 'Capture payments and allocate them to invoices'],

            // Phase 3 slice 5 — billing refunds & adjustments
            // (PRODUCT_REQUIREMENTS §6.13): requesting is one permission,
            // approving is another (segregation of duties — the requester
            // can never approve, enforced in BillingService).
            'billing:refund' => ['domain' => 'billing', 'description' => 'Request refunds and adjustments against posted charges'],
            'billing:refund-approve' => ['domain' => 'billing', 'description' => 'Approve or reject refund/adjustment requests (financial gate)'],

            // Phase 3 slice 18 — daily cashier settlement (PRODUCT_REQUIREMENTS
            // §6.13): reconciling a day is a distinct financial act, kept out
            // of the billing_clerk role (the cashier whose drawer is settled
            // does not reconcile it — segregation of duties).
            'billing:reconcile' => ['domain' => 'billing', 'description' => 'Reconcile daily cashier settlements'],

            // Phase 3 slice 18 — insurance claims (PRODUCT_REQUIREMENTS
            // §6.14): building/submitting/tracking a claim is one
            // permission; recording the payer SETTLEMENT is another
            // (insurance:settle — money moves only under the finance gate).
            'insurance:claim' => ['domain' => 'insurance', 'description' => 'Build, submit, and track insurance claims'],
            'insurance:settle' => ['domain' => 'insurance', 'description' => 'Record payer settlements against claims'],

            // Phase 3 slice 6 — IPD admission/discharge (PRODUCT_REQUIREMENTS
            // §6.5): admit from an open encounter onto a live bed; discharge
            // releases the bed. Discharge requires clinical authority (the
            // encounter provider, enforced in the controller).
            'admission:view' => ['domain' => 'admission', 'description' => 'View inpatient admissions and discharge records within scope'],
            'admission:create' => ['domain' => 'admission', 'description' => 'Admit a patient from an open encounter and assign a bed'],
            'admission:discharge' => ['domain' => 'admission', 'description' => 'Discharge an inpatient (structured summary, bed release)'],

            // Phase 3 slice 13 — the remaining documented IPD workflow
            // (ROADMAP Phase 8, PRODUCT_REQUIREMENTS §6.5): audited bed/ward
            // transfers (doctor-approved), nursing documentation (notes +
            // vitals), and MAR administration. The nursing permissions are
            // the nurse's acts — clinical roles only, like pharmacy
            // dispensing.
            'admission:transfer' => ['domain' => 'admission', 'description' => 'Transfer an inpatient between beds or wards (audited, doctor-authorized)'],
            'nursing:document' => ['domain' => 'nursing', 'description' => 'Record and sign nursing notes and vital observations within scope'],
            'mar:administer' => ['domain' => 'mar', 'description' => 'Schedule and record medication administration on the MAR (identity-confirmed)'],

            // Phase 3 slice 14 — Emergency (ROADMAP Phase 9,
            // PRODUCT_REQUIREMENTS §6.6): minimal-data registration,
            // configurable triage, time-stamped ER events, and audited
            // admit/transfer/discharge disposition.
            'er:view' => ['domain' => 'er', 'description' => 'View the ER queue, registrations, triage, and event log within scope'],
            'er:register' => ['domain' => 'er', 'description' => 'Register a patient in the emergency department (minimal-data, possibly unidentified)'],
            'triage:assign' => ['domain' => 'er', 'description' => 'Assign or reassess the triage acuity level of an ER patient'],
            'er:document' => ['domain' => 'er', 'description' => 'Append time-stamped events to the ER record'],
            'er:disposition' => ['domain' => 'er', 'description' => 'Dispose of an ER visit (admit to IPD / transfer / discharge) and triage overrides'],
            'er:manage' => ['domain' => 'er', 'description' => 'Configure the facility triage scales (the acuity catalog)'],

            // Phase 3 slice 19 — HR (ROADMAP Phase 15, PRODUCT_REQUIREMENTS
            // §6.17): positions, shift templates + rosters, attendance with
            // approved corrections, leave with balance tracking, and audited
            // payroll-ready exports. HR is org-scoped; staff personal data
            // is protected to the same standard as patient data.
            'hr:employee' => ['domain' => 'hr', 'description' => 'Manage the employee/position catalog within scope'],
            'hr:roster' => ['domain' => 'hr', 'description' => 'Manage shift templates and rosters (conflict detection)'],
            'hr:attendance' => ['domain' => 'hr', 'description' => 'Record attendance and approve corrections'],
            'hr:leave' => ['domain' => 'hr', 'description' => 'Manage leave types and approve/reject leave requests'],
            'hr:payroll_export' => ['domain' => 'hr', 'description' => 'Generate audited payroll-ready exports'],

            // Phase 3 slice 19 — Assets (ROADMAP Phase 15, PRODUCT_REQUIREMENTS
            // §6.18): register, transfer, maintain, and retire equipment.
            // Register covers the register + categories + deploy; retirement
            // is its own terminal act; maintenance covers schedules, work
            // orders, and IoT-ready readings.
            'assets:register' => ['domain' => 'assets', 'description' => 'Register assets, categories, and deploy equipment'],
            'assets:transfer' => ['domain' => 'assets', 'description' => 'Transfer assets between locations (append-only history)'],
            'assets:maintain' => ['domain' => 'assets', 'description' => 'Manage maintenance schedules, work orders, and IoT-ready readings'],
            'assets:retire' => ['domain' => 'assets', 'description' => 'Retire assets (terminal lifecycle act)'],
        ];
    }

    /**
     * Permission scope (TENANCY.md V2 §8): which contexts may exercise a
     * permission. 'tenant' = only inside a tenant (or support) context;
     * 'platform' = only platform context (platform administration);
     * 'both' = platform administration AND tenant administration. Tenant
     * business permissions are 'tenant'-scope by default, so a platform
     * administrator can never reach tenant data without a support session.
     *
     * @return array<string, string>
     */
    public static function scopes(): array
    {
        return [
            // Platform provisioning and support administration.
            'organization:manage' => 'platform',
            'support:manage' => 'platform',
            // Identity/roles/audit: exercised by platform administration and
            // tenant administration alike.
            'organization:view' => 'both',
            'user:view' => 'both',
            'user:create' => 'both',
            'role:view' => 'both',
            'role:assign' => 'both',
            'role:revoke' => 'both',
            'audit:view' => 'both',
        ];
    }

    /**
     * @return array<string, array{name: string, scope_type: string, permissions: list<string>}>
     */
    public static function catalog(): array
    {
        return [
            'superadmin' => [
                'name' => 'Superadmin',
                'scope_type' => 'platform',
                // All permissions, but platform context can only EXERCISE the
                // 'platform'/'both'-scoped ones (TENANCY.md V2 §8): tenant
                // data requires an explicit support session.
                'permissions' => array_keys(self::permissionCatalog()),
            ],
            // Read-only tenant context synthesized by an active support
            // session (TENANCY.md V2 §8). Never granted through the normal
            // assignment APIs; the session middleware constructs it at
            // runtime so every action stays attributable to the platform
            // administrator who opened the session.
            'support_agent' => [
                'name' => 'Support Agent (read-only)',
                'scope_type' => 'organization',
                'permissions' => [
                    'organization:view',
                    'facility:view',
                    'user:view',
                    'role:view',
                    'audit:view',
                    'branch:view',
                    'department:view', 'location:view', 'ward:view',
                    'room:view', 'bed:view', 'staff:view', 'service:view',
                    'settings:view',
                    'patient:view', 'patient:search',
                    'insurance:view', 'consent:view', 'document:view', 'payer:view',
                    'schedule:view', 'appointment:view', 'queue:view',
                    'encounter:view', 'medication:view', 'pharmacy:view', 'followup:view', 'billing:view',
                    'admission:view',
                    'er:view',
                ],
            ],
            'org_admin' => [
                'name' => 'Organization Admin',
                'scope_type' => 'organization',
                // Note: organization:manage is the PLATFORM provisioning
                // permission (TENANCY.md §12) — never granted to tenants.
                'permissions' => [
                    'organization:view',
                    'facility:view', 'facility:create',
                    'user:view', 'user:create',
                    'role:view', 'role:assign', 'role:revoke',
                    'audit:view',
                    // Tenancy V2 — branch administration.
                    'branch:view', 'branch:manage',
                    // Phase 4 — org admins administer every facility of
                    // their tenant's catalogs (TENANCY.md §7 rule 3).
                    'department:view', 'department:manage',
                    'location:view', 'location:manage',
                    'ward:view', 'ward:manage',
                    'room:view', 'room:manage',
                    'bed:view', 'bed:manage',
                    'staff:view', 'staff:manage',
                    'service:view', 'service:manage',
                    'settings:view', 'settings:manage',
                    // Phase 5 — patient master (org admins administer the
                    // whole tenant's patient index).
                    'patient:view', 'patient:register', 'patient:update',
                    'patient:search', 'patient:merge',
                    'insurance:view', 'insurance:manage',
                    'consent:view', 'consent:manage',
                    'document:view', 'document:manage',
                    'payer:view', 'payer:manage',
                    // Phase 6/7 — org admins administer the whole tenant's
                    // schedules, bookings, clinical record, and finance.
                    'schedule:view', 'schedule:manage',
                    'appointment:view', 'appointment:book', 'appointment:checkin', 'appointment:cancel',
                    'queue:view',
                    'encounter:view', 'encounter:create', 'encounter:document',
                    'encounter:prescribe', 'encounter:sign',
                    'medication:view', 'medication:manage',
                    'lab:view', 'lab:order', 'lab:manage',
                    'lab:acknowledge', 'lab:escalate', 'lab:correct',
                    'pharmacy:view', 'pharmacy:stock',
                    'followup:view', 'followup:manage',
                    'billing:view', 'billing:invoice', 'billing:collect',
                    'billing:refund', 'billing:refund-approve',
                    // Phase 3 slice 18 — the org admin reconciles and settles.
                    'billing:reconcile',
                    'insurance:claim', 'insurance:settle',
                    'admission:view', 'admission:create', 'admission:discharge',
                    'admission:transfer', 'nursing:document', 'mar:administer',
                    'er:view', 'er:register', 'triage:assign', 'er:document', 'er:disposition', 'er:manage',
                    // Phase 3 slice 16 — radiology (administer the whole
                    // tenant's radiology surface).
                    'radiology:view', 'radiology:order', 'radiology:schedule',
                    'radiology:perform', 'radiology:report', 'radiology:verify', 'radiology:manage',
                    // Phase 3 slice 19 — HR and Assets (administer the whole
                    // tenant's HR and equipment surfaces).
                    'hr:employee', 'hr:roster', 'hr:attendance', 'hr:leave', 'hr:payroll_export',
                    'assets:register', 'assets:transfer', 'assets:maintain', 'assets:retire',
                ],
            ],
            'org_finance' => [
                'name' => 'Organization Finance',
                'scope_type' => 'organization',
                // Phase 5: read access to the patient index and insurance
                // for billing work — no registration or clinical visibility.
                // Phase 3 slice 18: the finance officer reconciles daily
                // settlements and records payer claim settlements.
                'permissions' => [
                    'organization:view', 'audit:view',
                    'patient:view', 'patient:search',
                    'insurance:view', 'payer:view',
                    'billing:view',
                    'billing:reconcile',
                    'insurance:claim', 'insurance:settle',
                ],
            ],
            'hospital_admin' => [
                'name' => 'Hospital Admin',
                'scope_type' => 'facility',
                'permissions' => [
                    'facility:view', 'user:view', 'role:view', 'audit:view',
                    // Tenancy V2 — branch administration (facility scope).
                    'branch:view', 'branch:manage',
                    // Phase 4 — manages their facility's catalogs only
                    // (TENANCY.md §7: facility-scoped roles cover exactly
                    // their facility).
                    'department:view', 'department:manage',
                    'location:view', 'location:manage',
                    'ward:view', 'ward:manage',
                    'room:view', 'room:manage',
                    'bed:view', 'bed:manage',
                    'staff:view', 'staff:manage',
                    'service:view', 'service:manage',
                    'settings:view', 'settings:manage',
                    // Phase 5 — patient master (their facility only).
                    'patient:view', 'patient:register', 'patient:update',
                    'patient:search', 'patient:merge',
                    'insurance:view', 'insurance:manage',
                    'consent:view', 'consent:manage',
                    'document:view', 'document:manage',
                    'payer:view', 'payer:manage',
                    'lab:view', 'lab:manage', 'lab:acknowledge', 'lab:escalate', 'lab:correct',
                    // Phase 6/7 — manages their facility's front desk and
                    // clinical workflows.
                    'schedule:view', 'schedule:manage',
                    'appointment:view', 'appointment:book', 'appointment:checkin', 'appointment:cancel',
                    'queue:view',
                    'encounter:view', 'encounter:create', 'encounter:document',
                    'encounter:prescribe', 'encounter:sign',

                    'medication:view', 'medication:manage',
                    'pharmacy:view', 'pharmacy:stock',
                    'followup:view', 'followup:manage',
                    'billing:view', 'billing:invoice', 'billing:collect',
                    'billing:refund', 'billing:refund-approve',
                    // Phase 3 slice 18 — the hospital admin reconciles and settles.
                    'billing:reconcile',
                    'insurance:claim', 'insurance:settle',
                    'admission:view', 'admission:create', 'admission:discharge',
                    'admission:transfer', 'nursing:document', 'mar:administer',
                    'er:view', 'er:register', 'triage:assign', 'er:document', 'er:disposition', 'er:manage',
                    // Phase 3 slice 16 — radiology (administer the facility's
                    // radiology surface).
                    'radiology:view', 'radiology:order', 'radiology:schedule',
                    'radiology:perform', 'radiology:report', 'radiology:verify', 'radiology:manage',
                    // Phase 3 slice 19 — HR and Assets (administer the
                    // facility's HR and equipment surfaces).
                    'hr:employee', 'hr:roster', 'hr:attendance', 'hr:leave', 'hr:payroll_export',
                    'assets:register', 'assets:transfer', 'assets:maintain', 'assets:retire',
                ],
            ],
            'branch_manager' => [
                'name' => 'Branch Manager',
                'scope_type' => 'facility',
                // Phase 4: view-only visibility of their facility's catalogs.
                'permissions' => [
                    'facility:view', 'audit:view',
                    'branch:view',
                    'department:view', 'location:view', 'ward:view',
                    'room:view', 'bed:view', 'staff:view', 'service:view',
                    'settings:view',
                    // Phase 5 — view-only patient visibility.
                    'patient:view', 'patient:search',
                    'insurance:view', 'consent:view', 'document:view', 'payer:view',
                    // Phase 6/7 — read-only visibility of schedules, queue,
                    // clinical record, and bills.
                    'schedule:view', 'appointment:view', 'queue:view',
                    'encounter:view', 'medication:view', 'billing:view',
                    'admission:view',
                    'er:view',
                    // Phase 3 slice 16 — radiology read visibility.
                    'radiology:view',
                ],
            ],
            'receptionist' => [
                'name' => 'Receptionist',
                'scope_type' => 'facility',
                // Phase 5 — front-desk registration. patient:merge is
                // deliberately NOT granted (high-risk identity resolution).
                'permissions' => [
                    'patient:view', 'patient:register', 'patient:update',
                    'patient:search',
                    'insurance:view', 'insurance:manage',
                    'consent:view', 'consent:manage',
                    'document:view', 'document:manage',
                    'payer:view',
                    // Phase 6 — front desk: schedules, bookings, queue.
                    'schedule:view',
                    'appointment:view', 'appointment:book', 'appointment:checkin', 'appointment:cancel',
                    'queue:view',
                    // Phase 3 slice 14 — ER front desk: minimal-data
                    // registration and the triage-priority queue.
                    'er:view', 'er:register',
                ],
            ],
            'billing_clerk' => [
                'name' => 'Billing Clerk',
                'scope_type' => 'facility',
                // Phase 5/7 — patient visibility plus the full billing
                // surface (invoice issue, payment capture).
                'permissions' => [
                    'patient:view', 'patient:search',
                    'insurance:view', 'insurance:manage', 'payer:view',
                    'appointment:view', 'queue:view', 'encounter:view',
                    'billing:view', 'billing:invoice', 'billing:collect',
                    'billing:refund',
                    // Phase 3 slice 18 — the clerk builds/submits/tracks
                    // claims but does NOT settle them (segregation of
                    // duties) and does NOT reconcile its own drawer.
                    'insurance:claim',
                    'admission:view',
                ],
            ],
            'doctor' => [
                'name' => 'Doctor',
                'scope_type' => 'facility',
                // Phase 5/7 — the clinical workstation: read the record,
                // run the queue, document and sign encounters.
                'permissions' => [
                    'patient:view', 'patient:search',
                    'insurance:view', 'consent:view', 'document:view',
                    'schedule:view', 'appointment:view', 'queue:view',
                    'encounter:view', 'encounter:create', 'encounter:document',
                    'encounter:prescribe', 'encounter:sign',
                    'medication:view', 'pharmacy:view', 'followup:view', 'followup:manage', 'billing:view',
                    'lab:view', 'lab:order', 'lab:acknowledge',
                    'admission:view', 'admission:create', 'admission:discharge',
                    'admission:transfer',
                    // Phase 3 slice 14 — ER: the doctor triages (overrides
                    // require er:disposition), documents events, and disposes.
                    'er:view', 'triage:assign', 'er:document', 'er:disposition',
                    // Phase 3 slice 16 — radiology: the referring clinician
                    // orders imaging and views released reports.
                    'radiology:view', 'radiology:order',
                ],
            ],
            'nurse' => [
                'name' => 'Nurse',
                'scope_type' => 'facility',
                // Phase 5/7 — clinical read access; nurses document
                // alongside the provider but cannot prescribe.
                'permissions' => [
                    'patient:view', 'patient:search',
                    'insurance:view', 'consent:view', 'document:view',
                    'schedule:view', 'appointment:view', 'queue:view',
                    'encounter:view', 'encounter:document',
                    'medication:view', 'pharmacy:view', 'followup:view', 'billing:view',
                    'lab:view',
                    'admission:view',
                    // Phase 3 slice 13 — the nurse's IPD acts: nursing
                    // documentation (notes + vitals) and MAR administration.
                    'nursing:document', 'mar:administer',
                    // Phase 3 slice 14 — ER: nurses triage and document
                    // events; clinical authority (disposition/overrides)
                    // remains with the doctor.
                    'er:view', 'triage:assign', 'er:document',
                    // Phase 3 slice 16 — radiology read visibility.
                    'radiology:view',
                ],
            ],
            'pharmacist' => [
                'name' => 'Pharmacist',
                'scope_type' => 'facility',
                // Phase 5 — minimal patient visibility for dispensing;
                // Phase 3 slice 3 — the full dispensing surface: verify,
                // dispense (stock deduction), and stock management.
                // Phase 3 slice 8 — returns/reversals (the pharmacist's
                // clinical act, like dispensing).
                'permissions' => [
                    'patient:view', 'patient:search',
                    'pharmacy:view', 'pharmacy:dispense', 'pharmacy:stock',
                    'pharmacy:return',
                ],
            ],
            'lab_technician' => [
                'name' => 'Lab Technician',
                'scope_type' => 'facility',
                // Phase 5 — minimal patient visibility for specimen work;
                // Phase 3 slice 2 — specimen, processing, and result ENTRY
                // (never verification).
                'permissions' => [
                    'patient:view', 'patient:search',
                    'lab:view', 'lab:specimen', 'lab:process', 'lab:result_entry',
                ],
            ],
            'lab_supervisor' => [
                'name' => 'Lab Supervisor',
                'scope_type' => 'facility',
                // Phase 3 slice 2 — verification and report release: the
                // quality gate AFTER entry (entry ≠ verification).
                'permissions' => [
                    'patient:view', 'patient:search',
                    'lab:view', 'lab:verify', 'lab:report', 'lab:escalate', 'lab:correct',
                ],
            ],
            // Phase 3 slice 16 — Radiology roles (PRODUCT_REQUIREMENTS §6.9
            // users: radiographer, radiologist).
            'radiographer' => [
                'name' => 'Radiographer',
                'scope_type' => 'facility',
                // The imaging performer: schedules fall to the radiology
                // receptionist (radiology:schedule is granted to the
                // radiographer too — the department's scheduling act).
                'permissions' => [
                    'patient:view', 'patient:search',
                    'radiology:view', 'radiology:schedule', 'radiology:perform',
                ],
            ],
            'radiologist' => [
                'name' => 'Radiologist',
                'scope_type' => 'facility',
                // The report writer: drafts and amends reports
                // (radiology:report), and — as a DIFFERENT radiologist —
                // verifies another's report (radiology:verify, entry ≠
                // verification).
                'permissions' => [
                    'patient:view', 'patient:search',
                    'radiology:view', 'radiology:report', 'radiology:verify',
                ],
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::permissionCatalog() as $code => $definition) {
            Permission::query()->updateOrCreate(
                ['code' => $code],
                [
                    'domain' => $definition['domain'],
                    'description' => $definition['description'],
                    'scope' => self::scopes()[$code] ?? 'tenant',
                    'is_system' => true,
                ]
            );
        }

        foreach (self::catalog() as $code => $definition) {
            $role = Role::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $definition['name'],
                    'scope_type' => $definition['scope_type'],
                    'description' => 'System role: '.$definition['name'],
                    'is_system' => true,
                ]
            );

            $role->permissions()->sync(
                Permission::query()->whereIn('code', $definition['permissions'])->pluck('id')
            );
        }
    }
}
