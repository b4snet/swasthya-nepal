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
            'lab:manage' => ['domain' => 'lab', 'description' => 'Manage the lab/radiology test catalog'],

            // Billing and payments (Phase 13 spine, shipped with the
            // first clinical workflow).
            'billing:view' => ['domain' => 'billing', 'description' => 'View charges, invoices, and payments within scope'],
            'billing:invoice' => ['domain' => 'billing', 'description' => 'Issue invoices from posted charges'],
            'billing:collect' => ['domain' => 'billing', 'description' => 'Capture payments and allocate them to invoices'],
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
                    'encounter:view', 'medication:view', 'billing:view',
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
                    'billing:view', 'billing:invoice', 'billing:collect',
                ],
            ],
            'org_finance' => [
                'name' => 'Organization Finance',
                'scope_type' => 'organization',
                // Phase 5: read access to the patient index and insurance
                // for billing work — no registration or clinical visibility.
                'permissions' => [
                    'organization:view', 'audit:view',
                    'patient:view', 'patient:search',
                    'insurance:view', 'payer:view',
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
                    'lab:view', 'lab:manage',
                    // Phase 6/7 — manages their facility's front desk and
                    // clinical workflows.
                    'schedule:view', 'schedule:manage',
                    'appointment:view', 'appointment:book', 'appointment:checkin', 'appointment:cancel',
                    'queue:view',
                    'encounter:view', 'encounter:create', 'encounter:document',
                    'encounter:prescribe', 'encounter:sign',
                    'medication:view', 'medication:manage',
                    'billing:view', 'billing:invoice', 'billing:collect',
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
                    'medication:view', 'billing:view',
                    'lab:view', 'lab:order',
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
                    'medication:view', 'billing:view',
                    'lab:view',
                ],
            ],
            'pharmacist' => [
                'name' => 'Pharmacist',
                'scope_type' => 'facility',
                // Phase 5 — minimal patient visibility for dispensing.
                'permissions' => ['patient:view', 'patient:search'],
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
                    'lab:view', 'lab:verify', 'lab:report',
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
