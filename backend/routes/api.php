<?php

use App\Http\Controllers\Api\AdmissionController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BedController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\CriticalValueEventController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EncounterController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\FacilitySettingsController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsurancePolicyController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\LabOrderController;
use App\Http\Controllers\Api\LabTestController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MedicationController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PatientContactController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientDocumentController;
use App\Http\Controllers\Api\PatientIdentifierController;
use App\Http\Controllers\Api\PayerController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\PharmacyReturnController;
use App\Http\Controllers\Api\PlatformAssignmentController;
use App\Http\Controllers\Api\PlatformSupportController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\RoleAssignmentController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WardController;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Swasthya API — /api/v1
|--------------------------------------------------------------------------
|
| Versioned REST API (API_CONTRACTS.md §2). One envelope for success and
| error shapes.
|
| Every authenticated request passes through the tenant-context middleware:
| the context is DERIVED from the authenticated principal's active role
| assignments (never client input), and every endpoint is authorized with
| the `authorize:` gate (MASTER_RULES.md §8–9). There are no trusted
| internal endpoints that skip authorization.
|
| Health endpoints are intentionally not rate-limited: load balancers and
| orchestrators call them continuously (MASTER_RULES.md §20.2).
|
*/

Route::prefix('health')->group(function (): void {
    Route::get('live', [HealthController::class, 'live']);
    Route::get('ready', [HealthController::class, 'ready']);
});

// Public auth surface — strictest rate limits (API_CONTRACTS.md §15).
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');
// Public MFA challenge completion — the ONLY path to tokens for an
// MFA-enabled account (Phase 2; strictest throttle like login/refresh).
Route::post('auth/mfa/challenge', [MfaController::class, 'challenge'])->middleware('throttle:auth');

// Public password reset (Phase 2, SECURITY.md §5): request a single-use
// token and complete the reset. Both sit behind the strict auth throttle;
// reset additionally enforces per-account failure limiting in the service.
Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:auth');
Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:auth');

// The whole API surface is rate-limited per IP (throttle:api,
// SWASTHYA_RATE_LIMIT_API, config/swasthya.php §rate_limits) BEFORE
// authentication so unauthenticated scanners are counted too. Auth and MFA
// endpoints keep their own stricter limits above; writes keep the stricter
// throttle:writes where declared. Health endpoints remain unlimited.
Route::middleware(['throttle:api', 'auth:sanctum', ResolveTenantContext::class])->group(function (): void {
    // Session.
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // MFA lifecycle (Phase 2) — authenticated endpoints.
    Route::get('auth/mfa/status', [MfaController::class, 'status']);
    Route::post('auth/mfa/enroll', [MfaController::class, 'enroll'])->middleware('throttle:writes');
    Route::post('auth/mfa/activate', [MfaController::class, 'activate'])->middleware('throttle:writes');
    Route::post('auth/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:writes');
    Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->middleware('throttle:writes');
    Route::get('users/me', [UserController::class, 'me']);

    // Organizations.
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
    Route::post('organizations', [OrganizationController::class, 'store'])
        ->middleware('authorize:organization:manage');

    // Facilities.
    Route::get('organizations/{organization}/facilities', [FacilityController::class, 'index'])
        ->middleware('authorize:facility:view');
    Route::post('organizations/{organization}/facilities', [FacilityController::class, 'store'])
        ->middleware('authorize:facility:create');
    Route::get('facilities/{facility}', [FacilityController::class, 'show'])
        ->middleware('authorize:facility:view');

    // Branches (TENANCY.md V2 §4): optional operational sub-divisions of a
    // facility. Created inside a facility — the facility is the tenant anchor.
    Route::get('facilities/{facility}/branches', [BranchController::class, 'index'])
        ->middleware('authorize:branch:view');
    Route::post('facilities/{facility}/branches', [BranchController::class, 'store'])
        ->middleware('authorize:branch:manage');
    Route::get('branches/{branch}', [BranchController::class, 'show'])
        ->middleware('authorize:branch:view');
    Route::patch('branches/{branch}', [BranchController::class, 'update'])
        ->middleware('authorize:branch:manage');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])
        ->middleware('authorize:branch:manage');

    // Users.
    Route::get('users', [UserController::class, 'index'])
        ->middleware('authorize:user:view');
    Route::post('organizations/{organization}/users', [UserController::class, 'store'])
        ->middleware('authorize:user:create,role:assign');

    // Role assignments (grant / revoke).
    Route::post('organizations/{organization}/users/{user}/assignments', [RoleAssignmentController::class, 'grant'])
        ->middleware('authorize:role:assign');
    Route::delete('organizations/{organization}/users/{user}/assignments/{assignment}', [RoleAssignmentController::class, 'revoke'])
        ->middleware('authorize:role:revoke');

    // Platform administration (TENANCY.md V2 §8) — the platform boundary.

    // Tenant bootstrap: create the first facility + initial administrator.
    Route::post('platform/organizations/{organization}/provision', [OrganizationController::class, 'provision'])
        ->middleware('authorize:organization:manage');

    // Platform-scope role assignments (platform roles only — tenant roles are
    // granted by tenant administrators through the organization endpoints).
    Route::post('platform/users/{user}/assignments', [PlatformAssignmentController::class, 'grant'])
        ->middleware('authorize:role:assign');
    Route::delete('platform/users/{user}/assignments/{assignment}', [PlatformAssignmentController::class, 'revoke'])
        ->middleware('authorize:role:revoke');

    // Support sessions: the ONLY route into tenant data for platform staff —
    // explicit target, reason, expiry, full audit.
    Route::get('platform/support-sessions', [PlatformSupportController::class, 'index'])
        ->middleware('authorize:support:manage');
    Route::post('platform/support-sessions', [PlatformSupportController::class, 'store'])
        ->middleware('authorize:support:manage');
    Route::post('platform/support-sessions/{session}/end', [PlatformSupportController::class, 'end'])
        ->middleware('authorize:support:manage');

    // Catalogs.
    Route::get('roles', [RoleController::class, 'index'])
        ->middleware('authorize:role:view');
    Route::get('permissions', [PermissionController::class, 'index'])
        ->middleware('authorize:role:view');

    // Audit (audit:view only — no edit/delete path exists).
    Route::get('audit-events', [AuditController::class, 'index'])
        ->middleware('authorize:audit:view');

    // Phase 4 — Hospital Administration catalogs. Reads scope to the
    // caller's facility; writes resolve the facility from the context or a
    // validated facilityId (never trusted input, TENANCY.md §7).

    // Departments.
    Route::get('organizations/{organization}/departments', [DepartmentController::class, 'index'])
        ->middleware('authorize:department:view');
    Route::post('organizations/{organization}/departments', [DepartmentController::class, 'store'])
        ->middleware('authorize:department:manage');
    Route::get('departments/{department}', [DepartmentController::class, 'show'])
        ->middleware('authorize:department:view');
    Route::patch('departments/{department}', [DepartmentController::class, 'update'])
        ->middleware('authorize:department:manage');
    Route::delete('departments/{department}', [DepartmentController::class, 'destroy'])
        ->middleware('authorize:department:manage');

    // Locations.
    Route::get('organizations/{organization}/locations', [LocationController::class, 'index'])
        ->middleware('authorize:location:view');
    Route::post('organizations/{organization}/locations', [LocationController::class, 'store'])
        ->middleware('authorize:location:manage');
    Route::get('locations/{location}', [LocationController::class, 'show'])
        ->middleware('authorize:location:view');
    Route::patch('locations/{location}', [LocationController::class, 'update'])
        ->middleware('authorize:location:manage');
    Route::delete('locations/{location}', [LocationController::class, 'destroy'])
        ->middleware('authorize:location:manage');

    // Wards.
    Route::get('organizations/{organization}/wards', [WardController::class, 'index'])
        ->middleware('authorize:ward:view');
    Route::post('organizations/{organization}/wards', [WardController::class, 'store'])
        ->middleware('authorize:ward:manage');
    Route::get('wards/{ward}', [WardController::class, 'show'])
        ->middleware('authorize:ward:view');
    Route::patch('wards/{ward}', [WardController::class, 'update'])
        ->middleware('authorize:ward:manage');
    Route::delete('wards/{ward}', [WardController::class, 'destroy'])
        ->middleware('authorize:ward:manage');

    // Rooms (created inside a ward — the ward is the facility anchor).
    Route::get('organizations/{organization}/rooms', [RoomController::class, 'index'])
        ->middleware('authorize:room:view');
    Route::post('wards/{ward}/rooms', [RoomController::class, 'store'])
        ->middleware('authorize:room:manage');
    Route::get('rooms/{room}', [RoomController::class, 'show'])
        ->middleware('authorize:room:view');
    Route::patch('rooms/{room}', [RoomController::class, 'update'])
        ->middleware('authorize:room:manage');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])
        ->middleware('authorize:room:manage');

    // Beds (created inside a room; state transitions optimistic-locked).
    Route::get('organizations/{organization}/beds', [BedController::class, 'index'])
        ->middleware('authorize:bed:view');
    Route::post('rooms/{room}/beds', [BedController::class, 'store'])
        ->middleware('authorize:bed:manage');
    Route::get('beds/{bed}', [BedController::class, 'show'])
        ->middleware('authorize:bed:view');
    Route::patch('beds/{bed}', [BedController::class, 'update'])
        ->middleware('authorize:bed:manage');

    // Staff profiles.
    Route::get('organizations/{organization}/staff', [StaffController::class, 'index'])
        ->middleware('authorize:staff:view');
    Route::post('organizations/{organization}/staff', [StaffController::class, 'store'])
        ->middleware('authorize:staff:manage');
    Route::get('staff/{staff}', [StaffController::class, 'show'])
        ->middleware('authorize:staff:view');
    Route::patch('staff/{staff}', [StaffController::class, 'update'])
        ->middleware('authorize:staff:manage');

    // Hospital services catalog.
    Route::get('organizations/{organization}/services', [ServiceController::class, 'index'])
        ->middleware('authorize:service:view');
    Route::post('organizations/{organization}/services', [ServiceController::class, 'store'])
        ->middleware('authorize:service:manage');
    Route::get('services/{service}', [ServiceController::class, 'show'])
        ->middleware('authorize:service:view');
    Route::patch('services/{service}', [ServiceController::class, 'update'])
        ->middleware('authorize:service:manage');
    Route::delete('services/{service}', [ServiceController::class, 'destroy'])
        ->middleware('authorize:service:manage');

    // Facility configuration (versioned, audited settings as data).
    Route::get('facilities/{facility}/settings', [FacilitySettingsController::class, 'index'])
        ->middleware('authorize:settings:view');
    Route::put('facilities/{facility}/settings', [FacilitySettingsController::class, 'update'])
        ->middleware('authorize:settings:manage');
    Route::delete('facilities/{facility}/settings/{key}', [FacilitySettingsController::class, 'destroy'])
        ->middleware('authorize:settings:manage');

    // Phase 5 — Patient Master. Registration runs server-side duplicate
    // detection; merge is a separate high-risk permission. Search must be
    // declared BEFORE the {patient} wildcard route.

    // Payers (tenant catalog referenced by insurance policies).
    Route::get('organizations/{organization}/payers', [PayerController::class, 'index'])
        ->middleware('authorize:payer:view');
    Route::post('organizations/{organization}/payers', [PayerController::class, 'store'])
        ->middleware('authorize:payer:manage');

    // Patients.
    Route::get('organizations/{organization}/patients', [PatientController::class, 'index'])
        ->middleware('authorize:patient:view');
    Route::post('organizations/{organization}/patients', [PatientController::class, 'store'])
        ->middleware('authorize:patient:register');
    Route::get('patients/search', [PatientController::class, 'search'])
        ->middleware('authorize:patient:search');
    Route::get('patients/{patient}', [PatientController::class, 'show'])
        ->middleware('authorize:patient:view');
    Route::patch('patients/{patient}', [PatientController::class, 'update'])
        ->middleware('authorize:patient:update');
    Route::post('patients/{patient}/merge', [PatientController::class, 'merge'])
        ->middleware('authorize:patient:merge');
    Route::get('patients/{patient}/timeline', [PatientController::class, 'timeline'])
        ->middleware('authorize:patient:view');

    // Patient identifiers (encrypted at rest, duplicate-hashed).
    Route::get('patients/{patient}/identifiers', [PatientIdentifierController::class, 'index'])
        ->middleware('authorize:patient:view');
    Route::post('patients/{patient}/identifiers', [PatientIdentifierController::class, 'store'])
        ->middleware('authorize:patient:update');

    // Patient contacts (one active primary per type).
    Route::get('patients/{patient}/contacts', [PatientContactController::class, 'index'])
        ->middleware('authorize:patient:view');
    Route::post('patients/{patient}/contacts', [PatientContactController::class, 'store'])
        ->middleware('authorize:patient:update');
    Route::patch('patients/{patient}/contacts/{contact}', [PatientContactController::class, 'update'])
        ->middleware('authorize:patient:update');

    // Insurance policies (per patient, per payer).
    Route::get('patients/{patient}/insurance-policies', [InsurancePolicyController::class, 'index'])
        ->middleware('authorize:insurance:view');
    Route::post('patients/{patient}/insurance-policies', [InsurancePolicyController::class, 'store'])
        ->middleware('authorize:insurance:manage');
    Route::patch('insurance-policies/{policy}', [InsurancePolicyController::class, 'update'])
        ->middleware('authorize:insurance:manage');
    Route::post('insurance-policies/{policy}/cancel', [InsurancePolicyController::class, 'cancel'])
        ->middleware('authorize:insurance:manage');

    // Consents (versioned; capture + revoke audited).
    Route::get('patients/{patient}/consents', [ConsentController::class, 'index'])
        ->middleware('authorize:consent:view');
    Route::post('patients/{patient}/consents', [ConsentController::class, 'store'])
        ->middleware('authorize:consent:manage');
    Route::post('consents/{consent}/revoke', [ConsentController::class, 'revoke'])
        ->middleware('authorize:consent:manage');

    // Patient documents (metadata + encrypted storage reference).
    Route::get('patients/{patient}/documents', [PatientDocumentController::class, 'index'])
        ->middleware('authorize:document:view');
    Route::post('patients/{patient}/documents', [PatientDocumentController::class, 'store'])
        ->middleware('authorize:document:manage');

    // Phase 6 — Front Desk: schedules, availability, bookings, queue.
    Route::get('organizations/{organization}/schedule-templates', [ScheduleController::class, 'templates'])
        ->middleware('authorize:schedule:view');
    Route::post('organizations/{organization}/schedule-templates', [ScheduleController::class, 'storeTemplate'])
        ->middleware('authorize:schedule:manage');
    Route::get('organizations/{organization}/schedule-exceptions', [ScheduleController::class, 'exceptions'])
        ->middleware('authorize:schedule:view');
    Route::post('organizations/{organization}/schedule-exceptions', [ScheduleController::class, 'storeException'])
        ->middleware('authorize:schedule:manage');
    Route::get('staff/{staff}/availability', [ScheduleController::class, 'availability'])
        ->middleware('authorize:schedule:view');

    // Appointments (booking validates derived availability + unique slot).
    // NOTE: 'appointments/queue' must be declared BEFORE the {appointment}
    // wildcard or it is swallowed by implicit binding.
    Route::get('appointments/queue', [AppointmentController::class, 'queue'])
        ->middleware('authorize:queue:view');
    Route::get('appointments', [AppointmentController::class, 'index'])
        ->middleware('authorize:appointment:view');
    Route::post('appointments', [AppointmentController::class, 'store'])
        ->middleware('authorize:appointment:book');
    Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])
        ->middleware('authorize:appointment:view');
    Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
        ->middleware('authorize:appointment:checkin');
    Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])
        ->middleware('authorize:appointment:cancel');

    // Phase 7 — OPD: the clinical spine.
    Route::post('appointments/{appointment}/start-encounter', [EncounterController::class, 'start'])
        ->middleware('authorize:encounter:create');
    Route::get('encounters/{encounter}', [EncounterController::class, 'show'])
        ->middleware('authorize:encounter:view');
    Route::get('encounters/{encounter}/notes', [EncounterController::class, 'notes'])
        ->middleware('authorize:encounter:view');
    Route::post('encounters/{encounter}/notes', [EncounterController::class, 'storeNote'])
        ->middleware('authorize:encounter:document');
    Route::post('encounters/{encounter}/notes/{note}/sign', [EncounterController::class, 'signNote'])
        ->middleware('authorize:encounter:sign');
    Route::post('encounters/{encounter}/diagnoses', [EncounterController::class, 'storeDiagnosis'])
        ->middleware('authorize:encounter:document');
    Route::post('encounters/{encounter}/prescriptions', [EncounterController::class, 'storePrescription'])
        ->middleware('authorize:encounter:prescribe');
    Route::post('encounters/{encounter}/sign', [EncounterController::class, 'sign'])
        ->middleware('authorize:encounter:sign');
    Route::get('encounters/{encounter}/charges', [EncounterController::class, 'charges'])
        ->middleware('authorize:billing:view');
    Route::post('encounters/{encounter}/invoice', [EncounterController::class, 'invoice'])
        ->middleware('authorize:billing:invoice');

    // Formulary (medications) — the prescription reference catalog.
    Route::get('organizations/{organization}/medications', [MedicationController::class, 'index'])
        ->middleware('authorize:medication:view');
    Route::post('organizations/{organization}/medications', [MedicationController::class, 'store'])
        ->middleware('authorize:medication:manage');

    // Phase 3 slice 2 — Laboratory & radiology order lifecycle.
    Route::get('organizations/{organization}/lab-tests', [LabTestController::class, 'index'])
        ->middleware('authorize:lab:view');
    Route::post('organizations/{organization}/lab-tests', [LabTestController::class, 'store'])
        ->middleware('authorize:lab:manage');
    Route::post('encounters/{encounter}/lab-orders', [LabOrderController::class, 'store'])
        ->middleware('authorize:lab:order');
    Route::get('encounters/{encounter}/lab-orders', [LabOrderController::class, 'forEncounter'])
        ->middleware('authorize:lab:view');
    Route::get('patients/{patient}/lab-orders', [LabOrderController::class, 'forPatient'])
        ->middleware('authorize:lab:view');
    Route::get('lab-orders/{labOrder}', [LabOrderController::class, 'show'])
        ->middleware('authorize:lab:view');
    Route::post('lab-orders/{labOrder}/collect', [LabOrderController::class, 'collect'])
        ->middleware('authorize:lab:specimen');
    Route::post('lab-orders/{labOrder}/process', [LabOrderController::class, 'process'])
        ->middleware('authorize:lab:process');
    Route::post('lab-orders/{labOrder}/results', [LabOrderController::class, 'enterResults'])
        ->middleware('authorize:lab:result_entry');
    Route::post('lab-orders/{labOrder}/verify', [LabOrderController::class, 'verify'])
        ->middleware('authorize:lab:verify');
    Route::post('lab-orders/{labOrder}/report', [LabOrderController::class, 'report'])
        ->middleware('authorize:lab:report');

    // Phase 3 slice 7 — laboratory critical-value escalation
    // (PRODUCT_REQUIREMENTS §6.8 workflow 6, CLINICAL_SAFETY §7): a critical
    // result flagged at entry triggers an event targeted at the ordering
    // clinician; the clinician acknowledges (who/when) or a supervisor
    // escalates it — fail loudly, never silently.
    Route::get('critical-value-events', [CriticalValueEventController::class, 'index'])
        ->middleware('authorize:lab:view');
    Route::post('critical-value-events/{criticalValueEvent}/acknowledge', [CriticalValueEventController::class, 'acknowledge'])
        ->middleware('authorize:lab:acknowledge');
    Route::post('critical-value-events/{criticalValueEvent}/escalate', [CriticalValueEventController::class, 'escalate'])
        ->middleware('authorize:lab:escalate');

    // Billing and payments.
    // Phase 3 slice 3 — pharmacy dispensing & inventory
    // (PRODUCT_REQUIREMENTS §6.9): prescription → verification → stock
    // check → dispense → inventory deduction → billing.
    Route::get('organizations/{organization}/inventory', [InventoryController::class, 'index'])
        ->middleware('authorize:pharmacy:view');
    Route::post('organizations/{organization}/inventory', [InventoryController::class, 'store'])
        ->middleware('authorize:pharmacy:stock');
    Route::post('inventory-items/{inventoryItem}/adjust', [InventoryController::class, 'adjust'])
        ->middleware('authorize:pharmacy:stock');
    Route::get('prescriptions/{prescription}', [PharmacyController::class, 'show'])
        ->middleware('authorize:pharmacy:view');
    Route::post('prescriptions/{prescription}/verify', [PharmacyController::class, 'verify'])
        ->middleware('authorize:pharmacy:dispense');
    Route::post('prescriptions/{prescription}/dispense', [PharmacyController::class, 'dispense'])
        ->middleware('authorize:pharmacy:dispense');

    // Phase 3 slice 8 — pharmacy returns & reversals (PRODUCT_REQUIREMENTS
    // §6.7): a pharmacist reverses a dispensed line — reason captured, stock
    // restored, reversal recorded, and the refund path opened against the
    // linked posted charge (billing approval remains the separate financial
    // gate).
    Route::post('prescription-lines/{prescriptionLine}/return', [PharmacyReturnController::class, 'store'])
        ->middleware('authorize:pharmacy:return');

    // Phase 3 slice 4 — discharge & follow-up (PRODUCT_REQUIREMENTS §6.7):
    // clinical close of the visit + planned return visits linked to it.
    Route::post('encounters/{encounter}/discharge', [EncounterController::class, 'discharge'])
        ->middleware('authorize:encounter:sign');
    Route::post('encounters/{encounter}/follow-ups', [FollowUpController::class, 'create'])
        ->middleware('authorize:followup:manage');
    Route::get('encounters/{encounter}/follow-ups', [FollowUpController::class, 'forEncounter'])
        ->middleware('authorize:followup:view');
    Route::get('patients/{patient}/follow-ups', [FollowUpController::class, 'forPatient'])
        ->middleware('authorize:followup:view');
    Route::post('follow-ups/{followUp}/book', [FollowUpController::class, 'book'])
        ->middleware('authorize:followup:manage');
    // Phase 3 slice 9 — appointment auto-creation from a follow-up plan: the
    // plan BECOMES the booking (the appointment is created from the plan and
    // linked in one atomic step) instead of linking a separately-booked
    // appointment.
    Route::post('follow-ups/{followUp}/auto-book', [FollowUpController::class, 'autoBook'])
        ->middleware('authorize:followup:manage');
    Route::post('follow-ups/{followUp}/cancel', [FollowUpController::class, 'cancel'])
        ->middleware('authorize:followup:manage');
    Route::post('follow-ups/{followUp}/complete', [FollowUpController::class, 'complete'])
        ->middleware('authorize:followup:manage');

    Route::get('invoices/{invoice}', [BillingController::class, 'showInvoice'])
        ->middleware('authorize:billing:view');
    Route::get('invoices/{invoice}/payments', [BillingController::class, 'payments'])
        ->middleware('authorize:billing:view');
    Route::post('invoices/{invoice}/pay', [BillingController::class, 'pay'])
        ->middleware('authorize:billing:collect');

    // Phase 3 slice 5 — billing refunds & adjustments (PRODUCT_REQUIREMENTS
    // §6.13): posted charge → refund/adjustment request → authorized
    // approval → immutable reversing entry. Approval is a distinct
    // permission (segregation of duties — the approver is never the
    // requester).
    Route::get('charges/{charge}/refunds', [RefundController::class, 'index'])
        ->middleware('authorize:billing:view');
    Route::post('charges/{charge}/refunds', [RefundController::class, 'store'])
        ->middleware('authorize:billing:refund');
    Route::post('refund-requests/{refundRequest}/approve', [RefundController::class, 'approve'])
        ->middleware('authorize:billing:refund-approve');
    Route::post('refund-requests/{refundRequest}/reject', [RefundController::class, 'reject'])
        ->middleware('authorize:billing:refund-approve');

    // Phase 3 slice 6 — IPD admission/discharge with bed release
    // (PRODUCT_REQUIREMENTS §6.5): admit from an open encounter onto a live
    // available bed, then discharge with a structured summary that releases
    // the bed. Bed claims are CAS-guarded — two clerks can never book the
    // same bed.
    Route::post('encounters/{encounter}/admissions', [AdmissionController::class, 'store'])
        ->middleware('authorize:admission:create');
    Route::get('admissions/{admission}', [AdmissionController::class, 'show'])
        ->middleware('authorize:admission:view');
    Route::post('admissions/{admission}/discharge', [AdmissionController::class, 'discharge'])
        ->middleware('authorize:admission:discharge');
});
