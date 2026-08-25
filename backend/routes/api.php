<?php

use App\Http\Controllers\Api\AdmissionController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BenefitRuleController;
use App\Http\Controllers\Api\BedController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BloodBankController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CdssController;
use App\Http\Controllers\Api\CommunicationController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\CriticalValueEventController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DoctorScheduleController;
use App\Http\Controllers\Api\DocumentCenterController;
use App\Http\Controllers\Api\DocumentPrefillController;
use App\Http\Controllers\Api\EncounterController;
use App\Http\Controllers\Api\ErController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\FacilitySettingsController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FinancialPeriodController;
use App\Http\Controllers\Api\FollowUpController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HospitalBrandingController;
use App\Http\Controllers\Api\HrController;
use App\Http\Controllers\Api\IcuController;
use App\Http\Controllers\Api\InsurancePolicyController;
use App\Http\Controllers\Api\InteropController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\IpdNursingController;
use App\Http\Controllers\Api\LabOrderController;
use App\Http\Controllers\Api\LabTestController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MedicationController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NumberingController;
use App\Http\Controllers\Api\NursingController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\OnboardingProfileController;
use App\Http\Controllers\Api\OncologyController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OtController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PatientContactController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientDocumentController;
use App\Http\Controllers\Api\PatientIdentifierController;
use App\Http\Controllers\Api\PatientImportController;
use App\Http\Controllers\Api\PatientPortalController;
use App\Http\Controllers\Api\PayerController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\PharmacyReturnController;
use App\Http\Controllers\Api\PlatformAssignmentController;
use App\Http\Controllers\Api\PlatformSupportController;
use App\Http\Controllers\Api\PortalActivationController;
use App\Http\Controllers\Api\ProcurementController;
use App\Http\Controllers\Api\RadiologyController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\NepalFinanceController;
use App\Http\Controllers\Api\TaxRuleController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\RefundController;
use App\Http\Controllers\Api\RevenueController;
use App\Http\Controllers\Api\RoleAssignmentController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\RpmController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StandaloneDispensingController;
use App\Http\Controllers\Api\TelehealthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WardController;
use App\Http\Middleware\ResolvePartnerContext;
use App\Http\Middleware\ResolvePortalContext;
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
    Route::get('env', [HealthController::class, 'envInfo']);
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

// Patient Portal login (Phase 3 slice 22, PRODUCT_REQUIREMENTS §6.2):
// identifier + password against a tenant disambiguated by organization
// code. Behind the strict auth throttle like staff login; the service
// layers DB-backed per-account lockout on top (SECURITY.md §18).
Route::post('portal/login', [PatientPortalController::class, 'login'])->middleware('throttle:auth');

// Phase 82 — Portal activation (public, no auth required).
Route::post('portal/activate/{token}', [PortalActivationController::class, 'activate'])->middleware('throttle:auth');
Route::get('portal/activate/{token}', [PortalActivationController::class, 'verifyToken'])->middleware('throttle:auth');
Route::post('portal/forgot-password', [PortalActivationController::class, 'requestPasswordReset'])->middleware('throttle:auth');

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
    Route::get('organizations/{organization}/beds/occupancy', [BedController::class, 'occupancy'])
        ->middleware('authorize:bed:view');
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

    // Patient CSV import (Phase 80).
    Route::get('organizations/{organization}/patients/import/template', [PatientImportController::class, 'template'])
        ->middleware('authorize:patient:register');
    Route::get('organizations/{organization}/patient-imports', [PatientImportController::class, 'index'])
        ->middleware('authorize:patient:view');
    Route::post('organizations/{organization}/patients/import', [PatientImportController::class, 'upload'])
        ->middleware('authorize:patient:register');
    Route::get('patient-imports/{import}', [PatientImportController::class, 'show'])
        ->middleware('authorize:patient:view');
    Route::put('patient-imports/{import}/mapping', [PatientImportController::class, 'setMapping'])
        ->middleware('authorize:patient:register');
    Route::post('patient-imports/{import}/preview', [PatientImportController::class, 'preview'])
        ->middleware('authorize:patient:register');
    Route::post('patient-imports/{import}/import', [PatientImportController::class, 'import'])
        ->middleware('authorize:patient:register');

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

    // Patient Portal — staff-managed surface (Phase 3 slice 22, PRODUCT
    // REQUIREMENTS §6.2): provisioning portal accounts, issuing and revoking
    // consent-bound access grants, and disabling accounts. All gated by
    // portal:manage; the patient is resolved inside the tenant context so
    // a cross-tenant/cross-facility patient is a 404 (no existence leak).
    Route::post('patients/{patient}/portal/invite', [PortalActivationController::class, 'sendInvitation'])
        ->middleware('authorize:portal:manage');
    Route::post('organizations/{organization}/patients/{patient}/portal', [PatientPortalController::class, 'provisionAccount'])
        ->middleware('authorize:portal:manage');
    Route::post('portal-accounts/{portalAccount}/grants', [PatientPortalController::class, 'grantAccess'])
        ->middleware('authorize:portal:manage');
    Route::post('portal-accounts/{portalAccount}/disable', [PatientPortalController::class, 'disableAccount'])
        ->middleware('authorize:portal:manage');
    Route::post('portal-access-grants/{grant}/revoke', [PatientPortalController::class, 'revokeGrantByStaff'])
        ->middleware('authorize:portal:manage');

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
    Route::get('patients/{patientId}/encounters', [EncounterController::class, 'byPatient'])
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

    // Phase 3 slice 15 — specimen custody (PRODUCT_REQUIREMENTS §6.8):
    // collection mints per-tenant accession numbers and advances the order;
    // accession → processing → completed | rejected records WHO/WHEN at each
    // custody step (the medico-legal specimen chain).
    Route::post('lab-orders/{labOrder}/specimens', [LabOrderController::class, 'collectSpecimens'])
        ->middleware('authorize:lab:specimen');
    Route::post('specimens/{specimen}/accession', [LabOrderController::class, 'accession'])
        ->middleware('authorize:lab:specimen');
    Route::post('specimens/{specimen}/process', [LabOrderController::class, 'processSpecimen'])
        ->middleware('authorize:lab:process');
    Route::post('specimens/{specimen}/complete', [LabOrderController::class, 'completeSpecimen'])
        ->middleware('authorize:lab:process');
    Route::post('specimens/{specimen}/reject', [LabOrderController::class, 'rejectSpecimen'])
        ->middleware('authorize:lab:process');

    // Phase 3 slice 15 — corrected result versions (CLINICAL_SAFETY §7): a
    // reported (immutable) order is opened for correction by the lab quality
    // gate (lab:correct), the corrected value is entered as version N+1
    // (lab:result_entry — the original always remains visible), and the
    // existing verify/report endpoints re-run the release discipline.
    Route::post('lab-orders/{labOrder}/correct', [LabOrderController::class, 'initiateCorrection'])
        ->middleware('authorize:lab:correct');
    Route::post('lab-orders/{labOrder}/corrected-results', [LabOrderController::class, 'enterCorrectedResults'])
        ->middleware('authorize:lab:result_entry');

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

    // Phase 3 slice 16 — Radiology (PRODUCT_REQUIREMENTS §6.9,
    // CLINICAL_SAFETY §8): order imaging from an encounter, run the
    // radiology worklist, manage the modality catalog, walk the study state
    // machine (ordered → scheduled → performed → reported), draft/verify/
    // amend reports, and attach DICOM references to performed studies.
    Route::post('encounters/{encounter}/radiology-orders', [RadiologyController::class, 'storeOrder'])
        ->middleware('authorize:radiology:order');
    Route::get('radiology/queue', [RadiologyController::class, 'queue'])
        ->middleware('authorize:radiology:view');
    Route::get('radiology/modalities', [RadiologyController::class, 'modalities'])
        ->middleware('authorize:radiology:view');
    Route::post('radiology/modalities', [RadiologyController::class, 'storeModality'])
        ->middleware('authorize:radiology:manage');
    Route::patch('radiology/modalities/{modality}', [RadiologyController::class, 'updateModality'])
        ->middleware('authorize:radiology:manage');
    Route::get('studies/{study}', [RadiologyController::class, 'showStudy'])
        ->middleware('authorize:radiology:view');
    Route::post('studies/{study}/schedule', [RadiologyController::class, 'schedule'])
        ->middleware('authorize:radiology:schedule');
    Route::post('studies/{study}/perform', [RadiologyController::class, 'perform'])
        ->middleware('authorize:radiology:perform');
    Route::post('studies/{study}/cancel', [RadiologyController::class, 'cancelStudy'])
        ->middleware('authorize:radiology:schedule');
    Route::post('studies/{study}/report', [RadiologyController::class, 'draftReport'])
        ->middleware('authorize:radiology:report');
    Route::post('studies/{study}/image-references', [RadiologyController::class, 'addImageReferences'])
        ->middleware('authorize:radiology:report');
    Route::post('radiology-reports/{report}/verify', [RadiologyController::class, 'verifyReport'])
        ->middleware('authorize:radiology:verify');
    Route::post('radiology-reports/{report}/amend', [RadiologyController::class, 'amendReport'])
        ->middleware('authorize:radiology:report');
    Route::get('patients/{patient}/radiology-reports', [RadiologyController::class, 'forPatient'])
        ->middleware('authorize:radiology:view');
    Route::get('patients/{patient}/imaging-history', [RadiologyController::class, 'imagingHistory'])
        ->middleware('authorize:radiology:view');
    Route::get('radiology/stats', [RadiologyController::class, 'stats'])
        ->middleware('authorize:radiology:view');

    // Billing and payments.
    // Phase 3 slice 3 — pharmacy dispensing & inventory
    // (PRODUCT_REQUIREMENTS §6.9): prescription → verification → stock
    // check → dispense → inventory deduction → billing.
    Route::get('organizations/{organization}/inventory', [InventoryController::class, 'index'])
        ->middleware('authorize:pharmacy:view');
    Route::get('inventory-items/{inventoryItem}/batches', [InventoryController::class, 'batches'])
        ->middleware('authorize:pharmacy:view');
    Route::post('organizations/{organization}/inventory', [InventoryController::class, 'store'])
        ->middleware('authorize:pharmacy:stock');
    Route::post('inventory-items/{inventoryItem}/adjust', [InventoryController::class, 'adjust'])
        ->middleware('authorize:pharmacy:stock');

    // Phase 14 — inventory & procurement (ROADMAP §15, PRODUCT_REQUIREMENTS
    // §6.15–6.16, DATABASE.md §3.31–3.32): reorder alerts, inter-facility
    // transfers, approval-gated adjustments, and the procurement chain
    // (vendor → request → approval → PO → GRN → three-way match).
    Route::get('organizations/{organization}/reorder-alerts', [InventoryController::class, 'reorderAlerts'])
        ->middleware('authorize:pharmacy:view');
    Route::post('inventory-transfers', [InventoryController::class, 'transfer'])
        ->middleware('authorize:inventory:transfer');
    Route::post('inventory-items/{inventoryItem}/adjustment-requests', [InventoryController::class, 'storeAdjustmentRequest'])
        ->middleware('authorize:inventory:adjust-request');
    Route::get('inventory-items/{inventoryItem}/adjustment-requests', [InventoryController::class, 'adjustmentRequests'])
        ->middleware('authorize:pharmacy:view');
    Route::post('inventory-adjustment-requests/{adjustmentRequest}/approve', [InventoryController::class, 'approveAdjustmentRequest'])
        ->middleware('authorize:inventory:adjust-approve');
    Route::post('inventory-adjustment-requests/{adjustmentRequest}/reject', [InventoryController::class, 'rejectAdjustmentRequest'])
        ->middleware('authorize:inventory:adjust-approve');

    Route::get('organizations/{organization}/procurement/vendors', [ProcurementController::class, 'indexVendors'])
        ->middleware('authorize:procurement:view');
    Route::post('organizations/{organization}/procurement/vendors', [ProcurementController::class, 'storeVendor'])
        ->middleware('authorize:procurement:contract');
    Route::post('vendors/{vendor}/blacklist', [ProcurementController::class, 'blacklistVendor'])
        ->middleware('authorize:procurement:contract');
    Route::get('vendors/{vendor}/contracts', [ProcurementController::class, 'indexContracts'])
        ->middleware('authorize:procurement:view');
    Route::post('vendors/{vendor}/contracts', [ProcurementController::class, 'storeContract'])
        ->middleware('authorize:procurement:contract');
    Route::get('organizations/{organization}/procurement/requests', [ProcurementController::class, 'indexRequests'])
        ->middleware('authorize:procurement:view');
    Route::post('organizations/{organization}/procurement/requests', [ProcurementController::class, 'storeRequest'])
        ->middleware('authorize:procurement:request');
    Route::get('purchase-requests/{purchaseRequest}', [ProcurementController::class, 'showRequest'])
        ->middleware('authorize:procurement:view');
    Route::post('purchase-requests/{purchaseRequest}/submit', [ProcurementController::class, 'submitRequest'])
        ->middleware('authorize:procurement:request');
    Route::post('purchase-requests/{purchaseRequest}/approve', [ProcurementController::class, 'approveRequest'])
        ->middleware('authorize:procurement:approve');
    Route::post('purchase-requests/{purchaseRequest}/reject', [ProcurementController::class, 'rejectRequest'])
        ->middleware('authorize:procurement:approve');
    Route::get('organizations/{organization}/procurement/orders', [ProcurementController::class, 'indexOrders'])
        ->middleware('authorize:procurement:view');
    Route::post('organizations/{organization}/procurement/orders', [ProcurementController::class, 'storeOrder'])
        ->middleware('authorize:procurement:order');
    Route::post('purchase-orders/{order}/confirm', [ProcurementController::class, 'confirmOrder'])
        ->middleware('authorize:procurement:order');
    Route::post('purchase-orders/{order}/close', [ProcurementController::class, 'closeOrder'])
        ->middleware('authorize:procurement:order');
    Route::post('purchase-orders/{order}/goods-receipts', [ProcurementController::class, 'receiveGoods'])
        ->middleware('authorize:procurement:receive');
    Route::post('goods-receipts/{grn}/match', [ProcurementController::class, 'matchReceipt'])
        ->middleware('authorize:procurement:receive');
    Route::get('purchase-orders/{order}/goods-receipts', [ProcurementController::class, 'indexReceipts'])
        ->middleware('authorize:procurement:view');
    Route::get('prescriptions', [PharmacyController::class, 'index'])
        ->middleware('authorize:pharmacy:view');
    Route::get('prescriptions/{prescription}', [PharmacyController::class, 'show'])
        ->middleware('authorize:pharmacy:view');
    Route::post('prescriptions/{prescription}/verify', [PharmacyController::class, 'verify'])
        ->middleware('authorize:pharmacy:dispense');
    Route::post('prescriptions/{prescription}/dispense', [PharmacyController::class, 'dispense'])
        ->middleware('authorize:pharmacy:dispense');

    // Phase 3 — STANDALONE dispensing records (PRODUCT_REQUIREMENTS §6.7
    // `dispensing` entity): a pharmacist dispenses a medication directly to
    // a patient with NO prescription — same batch/shelf CAS + posted charge.
    Route::post('dispensings', [StandaloneDispensingController::class, 'store'])
        ->middleware('authorize:pharmacy:dispense');

    // Phase 3 slice 8 — pharmacy returns & reversals (PRODUCT_REQUIREMENTS
    // §6.7): a pharmacist reverses a dispensed line — reason captured, stock
    // restored, reversal recorded, and the refund path opened against the
    // linked posted charge (billing approval remains the separate financial
    // gate).
    Route::post('prescription-lines/{prescriptionLine}/return', [PharmacyReturnController::class, 'store'])
        ->middleware('authorize:pharmacy:return');

    // Phase 3 slice 17 — controlled-substance dual verification: a SECOND
    // pharmacist (different staff member) stamps a dispensed controlled
    // line before the dispense is complete (PRODUCT_REQUIREMENTS §6.7
    // workflow 2 — "verify by a second pharmacist where policy requires").
    Route::post('prescription-lines/{prescriptionLine}/dual-verify', [PharmacyController::class, 'dualVerify'])
        ->middleware('authorize:pharmacy:dispense');

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
    // Phase 3 slice 10 — follow-up reminders: trigger (idempotent) and read
    // the plan's in-app reminder (PRODUCT_REQUIREMENTS §5.4, DATABASE.md §3.37).
    Route::post('follow-ups/{followUp}/remind', [FollowUpController::class, 'remind'])
        ->middleware('authorize:followup:manage');
    Route::get('follow-ups/{followUp}/reminder', [FollowUpController::class, 'reminder'])
        ->middleware('authorize:followup:view');

    // Phase 53 — referral lifecycle: internal + external destinations
    Route::get('referrals', [ReferralController::class, 'index'])
        ->middleware('authorize:referral:view');
    Route::post('referrals', [ReferralController::class, 'store'])
        ->middleware('authorize:referral:create');
    Route::get('referrals/{referral}', [ReferralController::class, 'show'])
        ->middleware('authorize:referral:view');
    Route::post('referrals/{referral}/accept', [ReferralController::class, 'accept'])
        ->middleware('authorize:referral:manage');
    Route::post('referrals/{referral}/reject', [ReferralController::class, 'reject'])
        ->middleware('authorize:referral:manage');
    Route::post('referrals/{referral}/schedule', [ReferralController::class, 'schedule'])
        ->middleware('authorize:referral:manage');
    Route::post('referrals/{referral}/complete', [ReferralController::class, 'complete'])
        ->middleware('authorize:referral:manage');
    Route::post('referrals/{referral}/cancel', [ReferralController::class, 'cancel'])
        ->middleware('authorize:referral:manage');

    Route::get('invoices/{invoice}', [BillingController::class, 'showInvoice'])
        ->middleware('authorize:billing:view');
    Route::get('invoices/{invoice}/payments', [BillingController::class, 'payments'])
        ->middleware('authorize:billing:view');
    Route::post('invoices/{invoice}/pay', [BillingController::class, 'pay'])
        ->middleware('authorize:billing:collect');

    // Phase 13 — charge/invoice void (ROADMAP §14, DATABASE.md §3.33): void
    // is a status with required reason and approver — never a delete.
    // Restricted to billing:void: the clerk who charges cannot void
    // (segregation of duties — charge ≠ void). An invoice void cascades to
    // the charges it was built from (one atomic transaction).
    Route::post('charges/{charge}/void', [BillingController::class, 'voidCharge'])
        ->middleware('authorize:billing:void');
    Route::post('invoices/{invoice}/void', [BillingController::class, 'voidInvoice'])
        ->middleware('authorize:billing:void');

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
    // Phase 3 slice 11 — the approved refund's money is disbursed back to
    // the patient (the documented 'completed' state — DATABASE.md §3.33).
    Route::post('refund-requests/{refundRequest}/complete', [RefundController::class, 'complete'])
        ->middleware('authorize:billing:refund-approve');
    // Pharmacy return → billing notification: the billing team's in-app
    // view of the notification a pharmacy return created for this refund
    // request (DATABASE.md §3.30/§3.37).
    Route::get('refund-requests/{refundRequest}/notification', [RefundController::class, 'notification'])
        ->middleware('authorize:billing:view');

    // Phase 3 slice 18 — remaining Billing and Finance (PRODUCT_REQUIREMENTS
    // §6.13–6.14, DATABASE.md §3.33–3.35): deposits (collect/allocate),
    // patient-account aging, daily cashier settlements, and insurance
    // claims (build/submit/track/settle). No payment gateway is connected
    // (INTEROPERABILITY.md §13 — planned, no provider contract exists).
    Route::get('patients/{patient}/deposits', [FinanceController::class, 'deposits'])
        ->middleware('authorize:billing:view');
    Route::post('patients/{patient}/deposits', [FinanceController::class, 'collectDeposit'])
        ->middleware('authorize:billing:collect');
    Route::post('deposits/{deposit}/allocate', [FinanceController::class, 'allocateDeposit'])
        ->middleware('authorize:billing:collect');
    Route::get('patients/{patient}/aging', [FinanceController::class, 'aging'])
        ->middleware('authorize:billing:view');
    Route::get('cashier-settlements', [FinanceController::class, 'settlements'])
        ->middleware('authorize:billing:view');
    Route::post('cashier-settlements/reconcile', [FinanceController::class, 'reconcileSettlement'])
        ->middleware('authorize:billing:reconcile');
    Route::get('invoices/{invoice}/claims', [FinanceController::class, 'claims'])
        ->middleware('authorize:billing:view');
    Route::post('invoices/{invoice}/claims', [FinanceController::class, 'buildClaim'])
        ->middleware('authorize:insurance:claim');
    Route::get('claims/{claim}', [FinanceController::class, 'showClaim'])
        ->middleware('authorize:billing:view');
    Route::post('claims/{claim}/submit', [FinanceController::class, 'submitClaim'])
        ->middleware('authorize:insurance:claim');
    Route::post('claims/{claim}/reopen', [FinanceController::class, 'reopenClaim'])
        ->middleware('authorize:insurance:claim');
    Route::post('claims/{claim}/status', [FinanceController::class, 'recordClaimStatus'])
        ->middleware('authorize:insurance:claim');
    Route::post('claims/{claim}/settle', [FinanceController::class, 'settleClaim'])
        ->middleware('authorize:insurance:settle');

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

    // Phase 3 slice 13 — the remaining documented IPD workflow (ROADMAP
    // Phase 8, PRODUCT_REQUIREMENTS §6.5): audited bed/ward transfers,
    // nursing notes, MAR administration, and vital observations.
    Route::post('admissions/{admission}/transfer', [AdmissionController::class, 'transfer'])
        ->middleware('authorize:admission:transfer');
    Route::get('admissions/{admission}/transfers', [AdmissionController::class, 'transfers'])
        ->middleware('authorize:admission:view');

    Route::post('admissions/{admission}/nursing-notes', [IpdNursingController::class, 'storeNote'])
        ->middleware('authorize:nursing:document');
    Route::get('admissions/{admission}/nursing-notes', [IpdNursingController::class, 'indexNotes'])
        ->middleware('authorize:admission:view');
    Route::post('nursing-notes/{nursingNote}/sign', [IpdNursingController::class, 'signNote'])
        ->middleware('authorize:nursing:document');

    Route::post('admissions/{admission}/mar', [IpdNursingController::class, 'scheduleMar'])
        ->middleware('authorize:mar:administer');
    Route::get('admissions/{admission}/mar', [IpdNursingController::class, 'indexMar'])
        ->middleware('authorize:admission:view');
    Route::post('mar-entries/{marEntry}/administer', [IpdNursingController::class, 'administerMar'])
        ->middleware('authorize:mar:administer');

    Route::post('admissions/{admission}/vitals', [IpdNursingController::class, 'recordVital'])
        ->middleware('authorize:nursing:document');
    Route::get('admissions/{admission}/vitals', [IpdNursingController::class, 'indexVitals'])
        ->middleware('authorize:admission:view');

    // Phase 3 slice 14 — Emergency (ROADMAP Phase 9, PRODUCT_REQUIREMENTS
    // §6.6): minimal-data registration, configurable triage, time-stamped
    // ER events, and audited admit/transfer/discharge disposition. The
    // triage level IS the queue priority.
    Route::post('er/registrations', [ErController::class, 'storeRegistration'])
        ->middleware('authorize:er:register');
    Route::get('er/queue', [ErController::class, 'queue'])
        ->middleware('authorize:er:view');

    Route::get('organizations/{organization}/er/triage-scales', [ErController::class, 'indexScales'])
        ->middleware('authorize:er:view');
    Route::post('organizations/{organization}/er/triage-scales', [ErController::class, 'storeScale'])
        ->middleware('authorize:er:manage');
    Route::patch('er/triage-scales/{triageScale}', [ErController::class, 'updateScale'])
        ->middleware('authorize:er:manage');

    Route::post('er/encounters/{encounter}/triage', [ErController::class, 'assignTriage'])
        ->middleware('authorize:triage:assign');
    Route::get('er/encounters/{encounter}/events', [ErController::class, 'indexEvents'])
        ->middleware('authorize:er:view');
    Route::post('er/encounters/{encounter}/events', [ErController::class, 'storeEvent'])
        ->middleware('authorize:er:document');
    Route::post('er/encounters/{encounter}/disposition', [ErController::class, 'disposition'])
        ->middleware('authorize:er:disposition');

    // Phase 3 slice 19 — HR (ROADMAP Phase 15, PRODUCT_REQUIREMENTS §6.17,
    // DATABASE.md §3.45): positions, shift templates, rosters (conflict
    // detection), attendance with approved corrections, leave with balance
    // tracking, and audited payroll-ready exports. Staff personal data is
    // protected to the same standard as patient data.
    Route::get('positions', [HrController::class, 'positions'])
        ->middleware('authorize:hr:employee');
    Route::post('positions', [HrController::class, 'storePosition'])
        ->middleware('authorize:hr:employee');
    Route::get('shift-templates', [HrController::class, 'shiftTemplates'])
        ->middleware('authorize:hr:roster');
    Route::post('shift-templates', [HrController::class, 'storeShiftTemplate'])
        ->middleware('authorize:hr:roster');
    Route::get('rosters', [HrController::class, 'rosters'])
        ->middleware('authorize:hr:roster');
    Route::post('rosters', [HrController::class, 'storeRoster'])
        ->middleware('authorize:hr:roster');
    Route::post('rosters/{roster}/confirm', [HrController::class, 'confirmRoster'])
        ->middleware('authorize:hr:roster');
    Route::get('attendance', [HrController::class, 'attendance'])
        ->middleware('authorize:hr:attendance');
    Route::post('attendance', [HrController::class, 'storeAttendance'])
        ->middleware('authorize:hr:attendance');
    Route::post('attendance/{attendanceRecord}/correction', [HrController::class, 'requestCorrection'])
        ->middleware('authorize:hr:attendance');
    Route::post('attendance/{attendanceRecord}/correction/approve', [HrController::class, 'approveCorrection'])
        ->middleware('authorize:hr:attendance');
    Route::post('attendance/{attendanceRecord}/correction/reject', [HrController::class, 'rejectCorrection'])
        ->middleware('authorize:hr:attendance');
    Route::get('leave-types', [HrController::class, 'leaveTypes'])
        ->middleware('authorize:hr:leave');
    Route::post('leave-types', [HrController::class, 'storeLeaveType'])
        ->middleware('authorize:hr:leave');
    Route::get('leave-requests', [HrController::class, 'leaveRequests'])
        ->middleware('authorize:hr:leave');
    Route::post('leave-requests', [HrController::class, 'storeLeaveRequest'])
        ->middleware('authorize:hr:leave');
    Route::post('leave-requests/{leaveRequest}/approve', [HrController::class, 'approveLeaveRequest'])
        ->middleware('authorize:hr:leave');
    Route::post('leave-requests/{leaveRequest}/reject', [HrController::class, 'rejectLeaveRequest'])
        ->middleware('authorize:hr:leave');
    Route::get('payroll-exports', [HrController::class, 'payrollExports'])
        ->middleware('authorize:hr:payroll_export');
    Route::post('payroll-exports', [HrController::class, 'generatePayrollExport'])
        ->middleware('authorize:hr:payroll_export');

    // Phase 3 slice 19 — Assets (ROADMAP Phase 15, PRODUCT_REQUIREMENTS
    // §6.18, DATABASE.md §3.46): register + lifecycle (procured → deployed
    // → under_repair → retired), append-only transfers, maintenance
    // schedules, work orders with honest downtime, and the RFID/IoT-ready
    // reading model.
    Route::get('asset-categories', [AssetController::class, 'categories'])
        ->middleware('authorize:assets:register');
    Route::post('asset-categories', [AssetController::class, 'storeCategory'])
        ->middleware('authorize:assets:register');
    Route::get('assets', [AssetController::class, 'index'])
        ->middleware('authorize:assets:register');
    Route::post('assets', [AssetController::class, 'store'])
        ->middleware('authorize:assets:register');
    Route::post('assets/{asset}/deploy', [AssetController::class, 'deploy'])
        ->middleware('authorize:assets:register');
    Route::post('assets/{asset}/retire', [AssetController::class, 'retire'])
        ->middleware('authorize:assets:retire');
    Route::post('assets/{asset}/transfer', [AssetController::class, 'transfer'])
        ->middleware('authorize:assets:transfer');
    Route::get('assets/{asset}/transfers', [AssetController::class, 'transfers'])
        ->middleware('authorize:assets:register');
    Route::get('maintenance-schedules', [AssetController::class, 'maintenanceSchedules'])
        ->middleware('authorize:assets:maintain');
    Route::post('maintenance-schedules', [AssetController::class, 'storeMaintenanceSchedule'])
        ->middleware('authorize:assets:maintain');
    Route::get('work-orders', [AssetController::class, 'workOrders'])
        ->middleware('authorize:assets:maintain');
    Route::post('work-orders', [AssetController::class, 'openWorkOrder'])
        ->middleware('authorize:assets:maintain');
    Route::post('work-orders/{workOrder}/complete', [AssetController::class, 'completeWorkOrder'])
        ->middleware('authorize:assets:maintain');
    Route::post('work-orders/{workOrder}/cancel', [AssetController::class, 'cancelWorkOrder'])
        ->middleware('authorize:assets:maintain');
    Route::get('assets/{asset}/iot-readings', [AssetController::class, 'iotReadings'])
        ->middleware('authorize:assets:maintain');
    Route::post('assets/{asset}/iot-readings', [AssetController::class, 'storeIotReading'])
        ->middleware('authorize:assets:maintain');

    // Phase 3 slice 20 — Operating Theatre (ROADMAP Phase 16, PRODUCT
    // REQUIREMENTS §6.10, DATABASE.md §3.48): theatre scheduling with
    // conflict detection, procedure records, team/anesthesia/events,
    // structured safety checklists (compliance-gated closure), PACU
    // recovery.
    Route::get('theatres', [OtController::class, 'theatres'])
        ->middleware('authorize:ot:schedule');
    Route::post('theatres', [OtController::class, 'storeTheatre'])
        ->middleware('authorize:ot:schedule');
    Route::get('procedure-requests', [OtController::class, 'procedureRequests'])
        ->middleware('authorize:ot:schedule');
    Route::post('procedure-requests', [OtController::class, 'storeProcedureRequest'])
        ->middleware('authorize:ot:schedule');
    Route::post('procedure-requests/{procedureRequest}/schedule', [OtController::class, 'scheduleProcedureRequest'])
        ->middleware('authorize:ot:schedule');
    Route::post('procedure-requests/{procedureRequest}/cancel', [OtController::class, 'cancelProcedureRequest'])
        ->middleware('authorize:ot:schedule');
    Route::post('procedure-requests/{procedureRequest}/start', [OtController::class, 'startProcedure'])
        ->middleware('authorize:ot:document');
    Route::get('procedures/{procedure}', [OtController::class, 'showProcedure'])
        ->middleware('authorize:ot:document');
    Route::post('procedures/{procedure}/team', [OtController::class, 'addTeamMember'])
        ->middleware('authorize:ot:document');
    Route::post('procedures/{procedure}/anesthesia', [OtController::class, 'startAnesthesia'])
        ->middleware('authorize:ot:document');
    Route::post('procedures/{procedure}/events', [OtController::class, 'recordSurgicalEvent'])
        ->middleware('authorize:ot:document');
    Route::post('procedures/{procedure}/checklist/{item}/complete', [OtController::class, 'completeChecklistItem'])
        ->middleware('authorize:ot:checklist');
    Route::post('procedures/{procedure}/close', [OtController::class, 'closeProcedure'])
        ->middleware('authorize:ot:close');
    Route::post('procedures/{procedure}/recovery', [OtController::class, 'admitToRecovery'])
        ->middleware('authorize:ot:document');
    Route::post('recovery/{recoveryRecord}/discharge', [OtController::class, 'dischargeRecovery'])
        ->middleware('authorize:ot:document');

    // Phase 3 slice 20 — ICU / Critical Care (PRODUCT REQUIREMENTS §6.11,
    // DATABASE.md §3.49): acuity-based bed assignment, high-frequency
    // observations with COMPUTED warning scores, alerts that MUST be
    // acknowledged (score escalations, threshold breaches, MISSED
    // observations), critical-care documentation, step-down/discharge.
    Route::get('icu-beds', [IcuController::class, 'icuBeds'])
        ->middleware('authorize:icu:admit');
    Route::post('icu-beds', [IcuController::class, 'storeIcuBed'])
        ->middleware('authorize:icu:admit');
    Route::post('icu-admissions', [IcuController::class, 'admitToIcu'])
        ->middleware('authorize:icu:admit');
    Route::get('icu-admissions', [IcuController::class, 'admissions'])
        ->middleware('authorize:icu:observe');
    Route::get('icu-admissions/{icuAdmission}', [IcuController::class, 'showAdmission'])
        ->middleware('authorize:icu:observe');
    Route::post('icu-admissions/{icuAdmission}/observations', [IcuController::class, 'recordObservation'])
        ->middleware('authorize:icu:observe');
    Route::post('icu-alerts/{icuAlert}/acknowledge', [IcuController::class, 'acknowledgeAlert'])
        ->middleware('authorize:icu:observe');
    Route::post('icu-admissions/{icuAdmission}/notes', [IcuController::class, 'documentCare'])
        ->middleware('authorize:icu:document');
    Route::post('icu-admissions/{icuAdmission}/transfer', [IcuController::class, 'transferOut'])
        ->middleware('authorize:icu:transfer');

    // Phase 3 slice 20 — Blood Bank (PRODUCT REQUIREMENTS §6.12, DATABASE.md
    // §3.50): donors, componentized units with expiry, testing, compatibility
    // + crossmatch, issue (expired/untested never issuable), transfusion with
    // DUAL verification, reaction reporting, discard.
    Route::get('donors', [BloodBankController::class, 'donors'])
        ->middleware('authorize:bloodbank:register_donor');
    Route::post('donors', [BloodBankController::class, 'storeDonor'])
        ->middleware('authorize:bloodbank:register_donor');
    Route::post('donors/{donor}/donations', [BloodBankController::class, 'recordDonation'])
        ->middleware('authorize:bloodbank:process');
    Route::post('blood-units/{bloodUnit}/test', [BloodBankController::class, 'testBloodUnit'])
        ->middleware('authorize:bloodbank:process');
    Route::post('blood-units/{bloodUnit}/crossmatch', [BloodBankController::class, 'requestCrossmatch'])
        ->middleware('authorize:bloodbank:issue');
    Route::post('crossmatches/{crossmatch}/perform', [BloodBankController::class, 'performCrossmatch'])
        ->middleware('authorize:bloodbank:issue');
    Route::post('blood-units/{bloodUnit}/issue', [BloodBankController::class, 'issueBloodUnit'])
        ->middleware('authorize:bloodbank:issue');
    Route::get('transfusions', [BloodBankController::class, 'transfusions'])
        ->middleware('authorize:bloodbank:transfuse');
    Route::post('transfusions', [BloodBankController::class, 'startTransfusion'])
        ->middleware('authorize:bloodbank:transfuse');
    Route::post('transfusions/{transfusion}/verify', [BloodBankController::class, 'verifyTransfusion'])
        ->middleware('authorize:bloodbank:transfuse');
    Route::post('transfusions/{transfusion}/complete', [BloodBankController::class, 'completeTransfusion'])
        ->middleware('authorize:bloodbank:transfuse');
    Route::post('transfusions/{transfusion}/stop', [BloodBankController::class, 'stopTransfusion'])
        ->middleware('authorize:bloodbank:transfuse');
    Route::post('transfusions/{transfusion}/reaction', [BloodBankController::class, 'reportReaction'])
        ->middleware('authorize:bloodbank:transfuse');
    Route::get('blood-units', [BloodBankController::class, 'units'])
        ->middleware('authorize:bloodbank:process');
    Route::post('blood-units/{bloodUnit}/discard', [BloodBankController::class, 'discardBloodUnit'])
        ->middleware('authorize:bloodbank:discard');

    // Phase 52 — Nursing Workflow
    Route::get('nursing/tasks', [NursingController::class, 'tasks'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/tasks', [NursingController::class, 'storeTask'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/tasks/{nursingTask}/complete', [NursingController::class, 'completeTask'])
        ->middleware('authorize:nursing:document');
    Route::get('nursing/vitals', [NursingController::class, 'vitals'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/vitals', [NursingController::class, 'storeVital'])
        ->middleware('authorize:nursing:document');
    Route::get('nursing/care-plans', [NursingController::class, 'carePlans'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/care-plans', [NursingController::class, 'storeCarePlan'])
        ->middleware('authorize:nursing:document');
    Route::get('nursing/handovers', [NursingController::class, 'handovers'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/handovers', [NursingController::class, 'storeHandover'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/handovers/{shiftHandover}/accept', [NursingController::class, 'acceptHandover'])
        ->middleware('authorize:nursing:document');
    Route::get('nursing/alerts', [NursingController::class, 'alerts'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/alerts', [NursingController::class, 'storeAlert'])
        ->middleware('authorize:nursing:document');
    Route::post('nursing/alerts/{nursingAlert}/acknowledge', [NursingController::class, 'acknowledgeAlert'])
        ->middleware('authorize:nursing:document');

    // Phase — Form Library & Document Workflow
    Route::get('forms/templates', [FormController::class, 'indexTemplates'])
        ->middleware('authorize:forms:view');
    Route::get('forms/templates/{id}', [FormController::class, 'showTemplate'])
        ->middleware('authorize:forms:view');
    Route::post('forms/templates', [FormController::class, 'storeTemplate'])
        ->middleware('authorize:forms:manage');
    Route::put('forms/templates/{id}', [FormController::class, 'updateTemplate'])
        ->middleware('authorize:forms:manage');
    Route::post('forms/templates/{id}/publish', [FormController::class, 'publishTemplate'])
        ->middleware('authorize:forms:manage');
    Route::get('forms/submissions', [FormController::class, 'indexSubmissions'])
        ->middleware('authorize:forms:view');
    Route::get('forms/submissions/{id}', [FormController::class, 'showSubmission'])
        ->middleware('authorize:forms:view');
    Route::post('forms/submissions', [FormController::class, 'storeSubmission'])
        ->middleware('authorize:forms:create');
    Route::post('forms/submissions/{id}/submit', [FormController::class, 'submitForm'])
        ->middleware('authorize:forms:create');
    Route::post('forms/submissions/{id}/verify', [FormController::class, 'verifySubmission'])
        ->middleware('authorize:forms:verify');
    Route::post('forms/submissions/{id}/approve', [FormController::class, 'approveSubmission'])
        ->middleware('authorize:forms:approve');
    Route::post('forms/submissions/{id}/cancel', [FormController::class, 'cancelSubmission'])
        ->middleware('authorize:forms:manage');
    Route::post('forms/submissions/{id}/print', [FormController::class, 'recordPrint'])
        ->middleware('authorize:forms:view');
    Route::post('forms/submissions/{submissionId}/signatures', [FormController::class, 'addSignature'])
        ->middleware('authorize:forms:create');
    Route::get('forms/submissions/{submissionId}/signatures', [FormController::class, 'listSignatures'])
        ->middleware('authorize:forms:view');
    Route::post('forms/numbers', [FormController::class, 'generateNumber'])
        ->middleware('authorize:forms:create');
    Route::get('forms/categories', [FormController::class, 'indexCategories'])
        ->middleware('authorize:forms:view');

    // Phase 77 — Configurable Numbering System
    Route::get('numbering/types', [NumberingController::class, 'types'])
        ->middleware('authorize:forms:view');
    Route::get('numbering', [NumberingController::class, 'index'])
        ->middleware('authorize:forms:manage');
    Route::post('numbering', [NumberingController::class, 'store'])
        ->middleware('authorize:forms:manage');
    Route::get('numbering/{id}', [NumberingController::class, 'show'])
        ->middleware('authorize:forms:manage');
    Route::put('numbering/{id}', [NumberingController::class, 'update'])
        ->middleware('authorize:forms:manage');
    Route::get('numbering/{id}/preview', [NumberingController::class, 'preview'])
        ->middleware('authorize:forms:manage');
    Route::post('numbering/{id}/generate', [NumberingController::class, 'generate'])
        ->middleware('authorize:forms:create');

    // Hospital Branding & Document Configuration (Phase 78)
    Route::get('facilities/{facility}/branding', [HospitalBrandingController::class, 'show'])
        ->middleware('authorize:branding:view');
    Route::put('facilities/{facility}/branding', [HospitalBrandingController::class, 'update'])
        ->middleware('authorize:branding:manage');
    Route::get('facilities/{facility}/branding/document', [HospitalBrandingController::class, 'forDocument'])
        ->middleware('authorize:branding:view');

    // Phase 79 — Doctor Schedule Management
    Route::get('organizations/{organization}/doctors', [DoctorScheduleController::class, 'index'])
        ->middleware('authorize:schedule:view');
    Route::get('doctors/{staff}/weekly-schedule', [DoctorScheduleController::class, 'weeklySchedule'])
        ->middleware('authorize:schedule:view');
    Route::post('organizations/{organization}/doctors/{staff}/weekly-schedule', [DoctorScheduleController::class, 'updateWeeklySchedule'])
        ->middleware('authorize:schedule:manage');
    Route::get('organizations/{organization}/departments/{department}/schedule', [DoctorScheduleController::class, 'departmentSchedule'])
        ->middleware('authorize:schedule:view');

    // Phase 81 — Communication Templates
    Route::get('organizations/{organization}/communication-templates', [CommunicationController::class, 'index'])
        ->middleware('authorize:notification:view');
    Route::post('organizations/{organization}/communication-templates', [CommunicationController::class, 'store'])
        ->middleware('authorize:notification:manage');
    Route::get('communication-templates/{template}', [CommunicationController::class, 'show'])
        ->middleware('authorize:notification:view');
    Route::put('communication-templates/{template}', [CommunicationController::class, 'update'])
        ->middleware('authorize:notification:manage');
    Route::delete('communication-templates/{template}', [CommunicationController::class, 'destroy'])
        ->middleware('authorize:notification:manage');
    Route::post('communication-templates/{template}/preview', [CommunicationController::class, 'preview'])
        ->middleware('authorize:notification:view');
    Route::post('communication-templates/{template}/send', [CommunicationController::class, 'send'])
        ->middleware('authorize:notification:manage');
    Route::get('communication-templates/categories', [CommunicationController::class, 'categories'])
        ->middleware('authorize:notification:view');
    Route::get('communication-templates/variable-presets', [CommunicationController::class, 'variablePresets'])
        ->middleware('authorize:notification:view');

    // Phase 3 slice 21 — Analytics and Reporting (ROADMAP Phase 17, PRODUCT
    // REQUIREMENTS §6.19, DATABASE.md §3.51): versioned metric definitions,
    // Dashboard — real aggregate metrics for all role-specific dashboards.
    // Uses direct DB queries scoped by tenant + facility (via claims).
    Route::get('analytics/dashboard-metrics', [DashboardController::class, 'metrics'])
        ->middleware('authorize:analytics:view');
    Route::get('analytics/dashboard-charts', [DashboardController::class, 'charts'])
        ->middleware('authorize:analytics:view');

    // observed-only metric snapshots, curated dashboards with drill-down,
    // and scheduled replica-fed reports. analytics:view gates the read
    // surfaces; analytics:manage gates definitions/dashboards; reports:run
    // gates executions; reports:schedule gates schedules; reports:export
    // gates audited exports (MASTER_RULES.md §19.3).
    Route::get('analytics/kpi-definitions', [AnalyticsController::class, 'indexKpiDefinitions'])
        ->middleware('authorize:analytics:view');
    Route::post('analytics/kpi-definitions', [AnalyticsController::class, 'storeKpiDefinition'])
        ->middleware('authorize:analytics:manage');
    Route::post('analytics/kpi-definitions/{kpi}/supersede', [AnalyticsController::class, 'supersedeKpi'])
        ->middleware('authorize:analytics:manage');
    Route::get('analytics/metrics/{kpi}', [AnalyticsController::class, 'showMetrics'])
        ->middleware('authorize:analytics:view');
    Route::post('analytics/snapshots/refresh', [AnalyticsController::class, 'refreshMetrics'])
        ->middleware('authorize:analytics:manage');
    Route::post('analytics/dashboards', [AnalyticsController::class, 'storeDashboard'])
        ->middleware('authorize:analytics:manage');
    Route::get('analytics/dashboards/{dashboard}', [AnalyticsController::class, 'showDashboard'])
        ->middleware('authorize:analytics:view');
    Route::post('analytics/dashboards/{dashboard}/kpis', [AnalyticsController::class, 'addDashboardKpi'])
        ->middleware('authorize:analytics:manage');
    Route::get('analytics/report-templates', [AnalyticsController::class, 'indexReportTemplates'])
        ->middleware('authorize:reports:run');
    Route::post('analytics/report-templates', [AnalyticsController::class, 'storeReportTemplate'])
        ->middleware('authorize:reports:run');
    Route::get('analytics/report-schedules', [AnalyticsController::class, 'indexReportSchedules'])
        ->middleware('authorize:reports:schedule');
    Route::post('analytics/report-schedules', [AnalyticsController::class, 'storeReportSchedule'])
        ->middleware('authorize:reports:schedule');
    Route::get('analytics/report-runs', [AnalyticsController::class, 'indexReportRuns'])
        ->middleware('authorize:reports:run');
    Route::post('analytics/reports/run', [AnalyticsController::class, 'runReport'])
        ->middleware('authorize:reports:run');
    Route::post('analytics/reports/export', [AnalyticsController::class, 'exportReport'])
        ->middleware('authorize:reports:export');

    // Phase 18 — Domain dashboard summary (real-time data from source tables)
    Route::get('analytics/domain-summary/{domain}', [AnalyticsController::class, 'domainSummary'])
        ->middleware('authorize:analytics:view');

    // Phase 18 — Compliance Reporting
    Route::get('analytics/compliance-reports', [ComplianceController::class, 'indexComplianceReports'])
        ->middleware('authorize:analytics:view');
    Route::post('analytics/compliance-reports', [ComplianceController::class, 'storeComplianceReport'])
        ->middleware('authorize:analytics:manage');
    Route::get('compliance-reports/{report}', [ComplianceController::class, 'showComplianceReport'])
        ->middleware('authorize:analytics:view');
    Route::post('compliance-reports/{report}/items', [ComplianceController::class, 'storeItem'])
        ->middleware('authorize:analytics:manage');
    Route::post('compliance-reports/{report}/publish', [ComplianceController::class, 'publish'])
        ->middleware('authorize:analytics:manage');
    Route::post('compliance-reports/{report}/acknowledge', [ComplianceController::class, 'acknowledge'])
        ->middleware('authorize:analytics:view');
    Route::get('analytics/report-subscriptions', [ComplianceController::class, 'indexSubscriptions'])
        ->middleware('authorize:analytics:view');
    Route::post('analytics/report-subscriptions', [ComplianceController::class, 'storeSubscription'])
        ->middleware('authorize:analytics:view');
    Route::post('report-subscriptions/{subscription}/cancel', [ComplianceController::class, 'cancelSubscription'])
        ->middleware('authorize:analytics:view');
    Route::get('analytics/lineage/{reportRun}', [ComplianceController::class, 'lineage'])
        ->middleware('authorize:analytics:view');
    Route::get('analytics/template-versions/{template}', [ComplianceController::class, 'templateVersions'])
        ->middleware('authorize:analytics:view');
    Route::post('analytics/compliance-reports/{report}/export', [ComplianceController::class, 'exportComplianceReport'])
        ->middleware('authorize:reports:export');

    // Phase 3 slice 23 — Interoperability readiness (ROADMAP Phase 18,
    // INTEROPERABILITY.md §13–14): the integration registry with MEASURED
    // status, the egress allowlist (SSRF guard), and OAuth2 partner
    // registration. Nothing here connects to a live system — it records and
    // governs readiness truthfully.
    Route::get('interop/integrations', [InteropController::class, 'indexIntegrations'])
        ->middleware('authorize:integration:view');
    Route::post('interop/integrations', [InteropController::class, 'registerIntegration'])
        ->middleware('authorize:integration:manage');
    Route::post('interop/integrations/{integration}/status', [InteropController::class, 'recordIntegrationStatus'])
        ->middleware('authorize:integration:manage');
    Route::post('interop/integrations/{integration}/kill-switch', [InteropController::class, 'setKillSwitch'])
        ->middleware('authorize:integration:manage');
    Route::get('interop/egress-allowlist', [InteropController::class, 'indexEgressAllowlist'])
        ->middleware('authorize:integration:view');
    Route::post('interop/egress-allowlist', [InteropController::class, 'storeEgressDestination'])
        ->middleware('authorize:integration:manage');
    Route::get('interop/partners', [InteropController::class, 'indexPartners'])
        ->middleware('authorize:integration:view');
    Route::post('interop/partners', [InteropController::class, 'registerPartner'])
        ->middleware('authorize:integration:manage');
    Route::post('interop/partners/{partner}/revoke', [InteropController::class, 'revokePartner'])
        ->middleware('authorize:integration:manage');

    // Phase 3 slice 24 — Telehealth (ROADMAP Phase 19, PRODUCT_REQUIREMENTS
    // §6.20): virtual consultations in the SAME schedule/queue model, a
    // consent-gated secure video session (metadata only), an EXPLICIT
    // policy+consent-bound recording decision, a documented connectivity
    // fallback, and the shared Encounter (TYPE_TELECONSULT) for notes,
    // diagnoses, prescriptions, and sign-off at the SAME standard as OPD.
    Route::post('telehealth/schedule', [TelehealthController::class, 'schedule'])
        ->middleware('authorize:telehealth:schedule');
    Route::get('telehealth/teleconsults', [TelehealthController::class, 'index'])
        ->middleware('authorize:telehealth:conduct');
    Route::get('telehealth/teleconsults/{teleconsult}', [TelehealthController::class, 'show'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/teleconsults/{teleconsult}/ready', [TelehealthController::class, 'markReady'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/teleconsults/{teleconsult}/start', [TelehealthController::class, 'start'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/teleconsults/{teleconsult}/video-sessions', [TelehealthController::class, 'openVideoSession'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/video-sessions/{videoSession}/end', [TelehealthController::class, 'endVideoSession'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/video-sessions/{videoSession}/fail', [TelehealthController::class, 'failVideoSession'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/video-sessions/{videoSession}/recording', [TelehealthController::class, 'recording'])
        ->middleware('authorize:telehealth:record');
    Route::post('telehealth/teleconsults/{teleconsult}/complete', [TelehealthController::class, 'complete'])
        ->middleware('authorize:telehealth:conduct');
    Route::post('telehealth/teleconsults/{teleconsult}/cancel', [TelehealthController::class, 'cancel'])
        ->middleware('authorize:telehealth:schedule');

    // Phase 83 — Waiting room + patient teleconsult view.
    Route::get('telehealth/waiting-room', [TelehealthController::class, 'waitingRoom'])
        ->middleware('authorize:telehealth:conduct');
    Route::get('telehealth/my-consults', [TelehealthController::class, 'myConsults'])
        ->middleware('authorize:telehealth:conduct');

    // Phase 3 slice 25 — Remote Patient Monitoring (ROADMAP Phase 20):
    // device enrollment (consent-gated), validated+labeled ingestion
    // (idempotent batch), monitoring views, and human-mediated alerts with
    // acknowledgment. rpm:ingest is the machine/adapter path; the rest are
    // clinical roles.
    Route::post('rpm/devices', [RpmController::class, 'store'])
        ->middleware('authorize:rpm:manage');
    Route::get('rpm/devices', [RpmController::class, 'index'])
        ->middleware('authorize:rpm:view');
    Route::patch('rpm/devices/{rpmDevice}', [RpmController::class, 'update'])
        ->middleware('authorize:rpm:manage');
    Route::post('rpm/readings', [RpmController::class, 'ingest'])
        ->middleware('authorize:rpm:ingest');
    Route::get('rpm/patients/{patient}/readings', [RpmController::class, 'readings'])
        ->middleware('authorize:rpm:view');
    Route::get('rpm/alerts', [RpmController::class, 'alerts'])
        ->middleware('authorize:rpm:view');
    Route::post('rpm/alerts/{rpmAlert}/acknowledge', [RpmController::class, 'acknowledge'])
        ->middleware('authorize:rpm:acknowledge');
    Route::post('rpm/alerts/{rpmAlert}/resolve', [RpmController::class, 'resolve'])
        ->middleware('authorize:rpm:acknowledge');

    // Phase 21 — CDSS (ROADMAP Phase 21, CLINICAL_SAFETY.md §6, §9): the
    // versioned knowledge base (rules never edited in place — activation
    // supersedes), the knowledge checks (allergy/DDI/dose — fail open,
    // loudly), audited overrides, and advisory pathway suggestions.
    Route::get('cdss/rules', [CdssController::class, 'index'])
        ->middleware('authorize:cdss:view');
    Route::post('cdss/rules', [CdssController::class, 'store'])
        ->middleware('authorize:cdss:manage');
    Route::post('cdss/rules/{cdssRule}/activate', [CdssController::class, 'activate'])
        ->middleware('authorize:cdss:manage');
    Route::post('cdss/checks/prescription', [CdssController::class, 'checkPrescription'])
        ->middleware('authorize:cdss:view');
    Route::post('cdss/checks/{cdssCheckResult}/override', [CdssController::class, 'override'])
        ->middleware('authorize:cdss:manage');
    Route::post('cdss/pathways/{cdssRule}/evaluate', [CdssController::class, 'evaluatePathway'])
        ->middleware('authorize:cdss:view');

    // Phase 21 — Governed assistive AI (AI_RULES.md §1–§19): registry,
    // kill switches, gated invocation, and drafts that reach a record only
    // after clinician sign-off. No autonomous action path; degradation is
    // loud; no data to unapproved models.
    Route::get('ai/features', [AiController::class, 'index'])
        ->middleware('authorize:ai:view');
    Route::post('ai/features', [AiController::class, 'store'])
        ->middleware('authorize:ai:manage');
    Route::post('ai/features/{aiFeature}/activate', [AiController::class, 'activate'])
        ->middleware('authorize:ai:manage');
    Route::patch('ai/features/{aiFeature}/switch', [AiController::class, 'switch'])
        ->middleware('authorize:ai:manage');
    Route::post('ai/features/{aiFeature}/invoke', [AiController::class, 'invoke'])
        ->middleware('authorize:ai:invoke');
    Route::post('ai/drafts', [AiController::class, 'createDraft'])
        ->middleware('authorize:ai:invoke');
    Route::post('ai/drafts/{aiDraft}/sign', [AiController::class, 'sign'])
        ->middleware('authorize:ai:sign');

    // Module catalog and entitlements
    Route::get('modules/catalog', [ModuleController::class, 'catalog']);
    Route::get('modules/enabled', [ModuleController::class, 'enabled']);
    Route::get('modules/{moduleCode}/check', [ModuleController::class, 'check']);

    // Onboarding
    Route::post('onboarding', [OnboardingController::class, 'store']);
    Route::get('onboarding/{id}', [OnboardingController::class, 'show']);
    Route::put('onboarding/{id}', [OnboardingController::class, 'update']);
    Route::post('onboarding/{id}/activate', [OnboardingController::class, 'activate']);
    Route::get('onboarding/modules', [OnboardingController::class, 'modules']);
    Route::get('onboarding/modules/{moduleCode}/check', [OnboardingController::class, 'checkModule']);

    // Profile onboarding (first-login identity completion)
    Route::get('onboarding/profile/steps', [OnboardingProfileController::class, 'steps']);
    Route::post('onboarding/profile/step/{stepKey}', [OnboardingProfileController::class, 'saveStep']);
    Route::post('onboarding/profile/complete', [OnboardingProfileController::class, 'complete']);

    // Phase 84 — Centralized Document Center: browse, generate, verify,
    // sign, share, and download documents with hospital branding.
    // Named routes FIRST, then wildcard {document}.
    Route::get('documents/platform', [DocumentCenterController::class, 'platformIndex'])
        ->middleware('authorize:document:view');
    Route::get('documents/prefill', DocumentPrefillController::class)
        ->middleware('authorize:document:view');
    Route::get('documents/categories', [DocumentCenterController::class, 'categories'])
        ->middleware('authorize:document:view');
    Route::get('organizations/{organization}/documents', [DocumentCenterController::class, 'index'])
        ->middleware('authorize:document:view');
    Route::get('organizations/{organization}/documents/stats', [DocumentCenterController::class, 'stats'])
        ->middleware('authorize:document:view');
    Route::post('organizations/{organization}/documents/generate', [DocumentCenterController::class, 'generate'])
        ->middleware('authorize:document:manage');
    Route::get('documents/{document}', [DocumentCenterController::class, 'show'])
        ->middleware('authorize:document:view');
    Route::post('documents/{document}/verify', [DocumentCenterController::class, 'verify'])
        ->middleware('authorize:document:manage');
    Route::post('documents/{document}/sign', [DocumentCenterController::class, 'sign'])
        ->middleware('authorize:document:manage');
    Route::post('documents/{document}/share', [DocumentCenterController::class, 'share'])
        ->middleware('authorize:document:manage');
    Route::get('documents/{document}/pdf', [DocumentCenterController::class, 'downloadPdf'])
        ->middleware('authorize:document:view');
    Route::post('documents/{document}/pdf', [DocumentCenterController::class, 'regeneratePdf'])
        ->middleware('authorize:document:manage');
});

// Patient Portal — portal-authenticated surface (Phase 3 slice 22,
// PRODUCT_REQUIREMENTS §6.2): self-only, consent-bound reads. This group is
// deliberately OUTSIDE the staff tenant group — ResolvePortalContext
// replaces ResolveTenantContext (a portal token's tokenable is a
// PortalAccount, never a User), derives the patient identity from the token
// (never client input), loads the patient inside the tenant/facility RLS
// context, and carries NO role permissions — authorization is the portal's
// own scope checks. throttle:api still runs first so unauthenticated
// requests consume the per-IP budget.
Route::middleware(['throttle:api', 'auth:sanctum', ResolvePortalContext::class])->prefix('portal')->group(function (): void {
    Route::post('logout', [PatientPortalController::class, 'logout']);
    Route::get('me', [PatientPortalController::class, 'me']);
    Route::get('profile', [PatientPortalController::class, 'profile']);
    Route::get('appointments', [PatientPortalController::class, 'appointments']);
    Route::get('results', [PatientPortalController::class, 'results']);
    Route::get('bills', [PatientPortalController::class, 'bills']);
    Route::get('grants', [PatientPortalController::class, 'grants']);
    Route::post('grants/{grant}/revoke', [PatientPortalController::class, 'revokeGrant']);
    // PHR
    Route::get('medical-history', [PatientPortalController::class, 'medicalHistory']);
    Route::get('medications', [PatientPortalController::class, 'medications']);
    Route::get('lab-results', [PatientPortalController::class, 'labResults']);
    Route::get('radiology-reports', [PatientPortalController::class, 'radiologyReports']);
    Route::get('prescriptions', [PatientPortalController::class, 'prescriptions']);
    Route::get('documents', [PatientPortalController::class, 'documents']);
    Route::get('documents/{documentId}', [PatientPortalController::class, 'showDocument']);
    Route::get('documents/{documentId}/pdf', [PatientPortalController::class, 'downloadDocumentPdf']);
    Route::get('referrals', [PatientPortalController::class, 'referrals']);
    Route::get('immunizations', [PatientPortalController::class, 'immunizations']);
    // Messaging
    Route::get('messages', [PatientPortalController::class, 'messages']);
    Route::post('messages', [PatientPortalController::class, 'sendMessage']);
    // Notification preferences
    Route::get('notification-preferences', [PatientPortalController::class, 'notificationPreferences']);
    Route::put('notification-preferences', [PatientPortalController::class, 'updateNotificationPreferences']);
    // Consent management
    Route::get('consents', [PatientPortalController::class, 'consentRecords']);
    Route::post('consents/revoke', [PatientPortalController::class, 'revokeConsent']);
});

// Partner OAuth2 token endpoint (Phase 3 slice 23): public, behind the
// strict auth throttle like staff login; credentials are verified in
// PartnerOauthService (unknown client ≡ wrong secret — no enumeration).
Route::post('interop/oauth/token', [InteropController::class, 'issueToken'])->middleware('throttle:auth');

// Partner FHIR projection surface — OUTSIDE the staff tenant group:
// ResolvePartnerContext authenticates the partner access token and derives
// the tenant from it (never client input), projects ONLY the tenant claim
// (partners are tenant-level consumers), and every projection is gated by
// the token's scope AND an active data-use consent at the boundary.
Route::middleware(['throttle:api', ResolvePartnerContext::class])->prefix('interop/fhir')->group(function (): void {
    Route::get('Patient/{patient}', [InteropController::class, 'fhirPatient']);
    Route::get('Encounter/{encounter}', [InteropController::class, 'fhirEncounter']);
    Route::get('MedicationRequest/{prescription}', [InteropController::class, 'fhirMedicationRequest']);
    Route::get('DiagnosticReport/{labOrder}', [InteropController::class, 'fhirDiagnosticReport']);
});

// ── Phase 15: Oncology & Radiotherapy ──
Route::middleware(['throttle:api', ResolveTenantContext::class])->prefix('oncology')->group(function (): void {
    // Profiles
    Route::post('profiles', [OncologyController::class, 'storeProfile']);
    Route::get('profiles', [OncologyController::class, 'listProfiles']);
    Route::get('profiles/{profile}', [OncologyController::class, 'showProfile']);

    // Diagnoses
    Route::post('profiles/{profile}/diagnoses', [OncologyController::class, 'storeDiagnosis']);

    // Treatment Plans
    Route::post('profiles/{profile}/treatment-plans', [OncologyController::class, 'storeTreatmentPlan']);
    Route::get('treatment-plans/{plan}', [OncologyController::class, 'showTreatmentPlan']);
    Route::post('treatment-plans/{plan}/start', [OncologyController::class, 'startCycle']);

    // Cycles
    Route::post('cycles/{cycle}/complete', [OncologyController::class, 'completeCycle']);
    Route::post('cycles/{cycle}/toxicity', [OncologyController::class, 'storeToxicity']);

    // Oncology Encounters
    Route::post('profiles/{profile}/encounters', [OncologyController::class, 'storeOncologyEncounter']);

    // MDT Reviews
    Route::get('profiles/{profile}/mdt-reviews', [OncologyController::class, 'listMdtReviews']);
    Route::post('profiles/{profile}/mdt-reviews', [OncologyController::class, 'storeMdtReview']);

    // RT Courses
    Route::post('profiles/{profile}/rt-courses', [OncologyController::class, 'storeRtCourse']);
    Route::get('rt-courses/{course}', [OncologyController::class, 'showRtCourse']);

    // RT Plans
    Route::post('rt-courses/{course}/plans', [OncologyController::class, 'storeRtPlan']);
    Route::post('rt-plans/{plan}/submit', [OncologyController::class, 'submitRtPlan']);
    Route::post('rt-plans/{plan}/physicist-check', [OncologyController::class, 'physicistCheck']);
    Route::post('rt-plans/{plan}/secondary-check', [OncologyController::class, 'secondaryCheck']);
    Route::post('rt-plans/{plan}/ro-approval', [OncologyController::class, 'roApproval']);

    // RT Fractions
    Route::get('rt-plans/{plan}/fractions', [OncologyController::class, 'listFractions']);
    Route::post('rt-fractions/{fraction}/deliver', [OncologyController::class, 'deliverFraction']);

    // RT Machines
    Route::get('rt-machines', [OncologyController::class, 'listMachines']);
    Route::post('rt-machines', [OncologyController::class, 'storeMachine']);

    // RT Structures
    Route::post('rt-plans/{plan}/structures', [OncologyController::class, 'storeStructure']);

    // Stats
    Route::get('stats', [OncologyController::class, 'stats']);
});

// ── Specialty Care Framework ──
Route::middleware(['throttle:api', ResolveTenantContext::class])->prefix('specialty')->group(function (): void {
    Route::get('profiles', [SpecialtyController::class, 'listProfiles']);
    Route::post('profiles', [SpecialtyController::class, 'storeProfile']);
    Route::get('profiles/{specialtyProfile}', [SpecialtyController::class, 'showProfile']);
    Route::post('profiles/{specialtyProfile}/assessments', [SpecialtyController::class, 'storeAssessment']);
    Route::post('profiles/{specialtyProfile}/care-plans', [SpecialtyController::class, 'storeCarePlan']);
    Route::post('care-plans/{carePlan}/activate', [SpecialtyController::class, 'activateCarePlan']);
    Route::post('care-plans/{carePlan}/complete', [SpecialtyController::class, 'completeCarePlan']);
});

// ── Phase 17: Enterprise Budget, Expense & Financial Periods ──
Route::middleware(['throttle:api', ResolveTenantContext::class])->prefix('enterprise')->group(function (): void {
    // Budgets
    Route::get('organizations/{organization}/budgets', [BudgetController::class, 'index']);
    Route::post('organizations/{organization}/budgets', [BudgetController::class, 'store']);
    Route::get('budgets/{budget}', [BudgetController::class, 'show']);
    Route::post('budgets/{budget}/approve', [BudgetController::class, 'approve']);
    Route::post('budgets/{budget}/close', [BudgetController::class, 'close']);
    Route::post('budgets/{budget}/lines', [BudgetController::class, 'storeLine']);

    // Expense Categories
    Route::get('organizations/{organization}/expense-categories', [BudgetController::class, 'indexCategories']);
    Route::post('organizations/{organization}/expense-categories', [BudgetController::class, 'storeCategory']);

    // Expenses
    Route::get('organizations/{organization}/expenses', [ExpenseController::class, 'index']);
    Route::post('organizations/{organization}/expenses', [ExpenseController::class, 'store']);
    Route::get('expenses/{expense}', [ExpenseController::class, 'show']);
    Route::post('expenses/{expense}/submit', [ExpenseController::class, 'submit']);
    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve']);
    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject']);
    Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay']);
    Route::post('expenses/{expense}/void', [ExpenseController::class, 'void']);

    // Financial Periods
    Route::get('organizations/{organization}/financial-periods', [FinancialPeriodController::class, 'index']);
    Route::post('organizations/{organization}/financial-periods', [FinancialPeriodController::class, 'store']);
    Route::get('financial-periods/{period}', [FinancialPeriodController::class, 'show']);
    Route::post('financial-periods/{period}/close', [FinancialPeriodController::class, 'close']);
    Route::post('financial-periods/{period}/lock', [FinancialPeriodController::class, 'lock']);
    Route::post('financial-periods/{period}/reopen', [FinancialPeriodController::class, 'reopen']);

    // Nepal Financial Architecture — Tax Rules (effective-dated)
    Route::get('finance/tax-rules', [TaxRuleController::class, 'index'])
        ->middleware('authorize:billing:view');
    Route::post('finance/tax-rules', [TaxRuleController::class, 'store'])
        ->middleware('authorize:billing:manage');
    Route::get('finance/tax-rules/{taxRule}', [TaxRuleController::class, 'show'])
        ->middleware('authorize:billing:view');
    Route::patch('finance/tax-rules/{taxRule}', [TaxRuleController::class, 'update'])
        ->middleware('authorize:billing:manage');
    Route::delete('finance/tax-rules/{taxRule}', [TaxRuleController::class, 'destroy'])
        ->middleware('authorize:billing:manage');

    // Nepal Financial Architecture — Benefit Rules (versioned, per-payer)
    Route::get('finance/payers/{payer}/benefit-rules', [BenefitRuleController::class, 'index'])
        ->middleware('authorize:billing:view');
    Route::post('finance/payers/{payer}/benefit-rules', [BenefitRuleController::class, 'store'])
        ->middleware('authorize:billing:manage');
    Route::get('finance/payers/{payer}/benefit-rules/{benefitRule}', [BenefitRuleController::class, 'show'])
        ->middleware('authorize:billing:view');
    Route::patch('finance/payers/{payer}/benefit-rules/{benefitRule}', [BenefitRuleController::class, 'update'])
        ->middleware('authorize:billing:manage');
    Route::delete('finance/payers/{payer}/benefit-rules/{benefitRule}', [BenefitRuleController::class, 'destroy'])
        ->middleware('authorize:billing:manage');

    // Nepal Financial Administration — fiscal years, payers, claims
    Route::get('finance/fiscal-years', [NepalFinanceController::class, 'indexFiscalYears'])
        ->middleware('authorize:billing:view');
    Route::post('finance/fiscal-years', [NepalFinanceController::class, 'storeFiscalYear'])
        ->middleware('authorize:billing:manage');
    Route::post('finance/fiscal-years/{period}/close', [NepalFinanceController::class, 'closeFiscalYear'])
        ->middleware('authorize:billing:manage');
    Route::post('finance/fiscal-years/{period}/reopen', [NepalFinanceController::class, 'reopenFiscalYear'])
        ->middleware('authorize:billing:manage');

    Route::get('finance/payers', [NepalFinanceController::class, 'indexPayers'])
        ->middleware('authorize:billing:view');
    Route::post('finance/payers', [NepalFinanceController::class, 'storePayer'])
        ->middleware('authorize:billing:manage');

    Route::get('finance/claims', [NepalFinanceController::class, 'indexClaims'])
        ->middleware('authorize:billing:view');
});

// Phase 85 — Complete Revenue Cycle: reports, receipts, adjustments.
Route::get('organizations/{organization}/revenue/summary', [RevenueController::class, 'revenueSummary'])
    ->middleware('authorize:billing:view');
Route::get('organizations/{organization}/revenue/by-source', [RevenueController::class, 'revenueBySource'])
    ->middleware('authorize:billing:view');
Route::get('organizations/{organization}/revenue/daily-trend', [RevenueController::class, 'dailyTrend'])
    ->middleware('authorize:billing:view');
Route::get('organizations/{organization}/revenue/expense-summary', [RevenueController::class, 'expenseSummary'])
    ->middleware('authorize:billing:view');
Route::get('organizations/{organization}/revenue/aging', [RevenueController::class, 'agingAnalysis'])
    ->middleware('authorize:billing:view');
Route::get('budgets/{budget}/vs-actual', [RevenueController::class, 'budgetVsActual'])
    ->middleware('authorize:budget:view');
Route::get('financial-periods/{period}/summary', [RevenueController::class, 'periodSummary'])
    ->middleware('authorize:budget:view');

Route::get('payments/{payment}/receipt', [RevenueController::class, 'receipt'])
    ->middleware('authorize:billing:view');
Route::post('payments/{payment}/receipt', [RevenueController::class, 'generateReceipt'])
    ->middleware('authorize:billing:collect');
Route::post('receipts/{receipt}/print', [RevenueController::class, 'printReceipt'])
    ->middleware('authorize:billing:collect');

Route::get('invoices/{invoice}/adjustments', [RevenueController::class, 'adjustments'])
    ->middleware('authorize:billing:view');
Route::post('invoices/{invoice}/adjustments', [RevenueController::class, 'requestAdjustment'])
    ->middleware('authorize:billing:refund');
Route::post('billing-adjustments/{adjustment}/approve', [RevenueController::class, 'approveAdjustment'])
    ->middleware('authorize:billing:refund-approve');
Route::post('billing-adjustments/{adjustment}/apply', [RevenueController::class, 'applyAdjustment'])
    ->middleware('authorize:billing:refund-approve');
Route::post('billing-adjustments/{adjustment}/reject', [RevenueController::class, 'rejectAdjustment'])
    ->middleware('authorize:billing:refund-approve');

// Phase 86 — Realtime Operations Center: SSE streaming, polling, and receipt management.
Route::get('realtime/events', [RealtimeController::class, 'index']);
Route::post('realtime/events', [RealtimeController::class, 'store']);
Route::get('realtime/unread-count', [RealtimeController::class, 'unreadCount']);
Route::get('realtime/severity-counts', [RealtimeController::class, 'severityCounts']);
Route::post('realtime/events/mark-read', [RealtimeController::class, 'markRead']);
Route::post('realtime/events/mark-all-read', [RealtimeController::class, 'markAllRead']);
Route::post('realtime/events/{eventId}/acknowledge', [RealtimeController::class, 'acknowledge']);
Route::post('realtime/events/{eventId}/dismiss', [RealtimeController::class, 'dismiss']);
Route::get('realtime/stream', [RealtimeController::class, 'stream']);

// ── Phase 12: National Mass Notification Platform ──
Route::middleware(['throttle:api', ResolveTenantContext::class])->prefix('notifications')->group(function (): void {
    // Templates
    Route::get('templates', [NotificationController::class, 'indexTemplates'])
        ->middleware('authorize:notification:view');
    Route::post('templates', [NotificationController::class, 'storeTemplate'])
        ->middleware('authorize:notification:manage');

    // Audience Segments
    Route::get('segments', [NotificationController::class, 'indexSegments'])
        ->middleware('authorize:notification:view');
    Route::post('segments', [NotificationController::class, 'storeSegment'])
        ->middleware('authorize:notification:manage');

    // Broadcast Campaigns
    Route::get('campaigns', [NotificationController::class, 'indexCampaigns'])
        ->middleware('authorize:notification:view');
    Route::post('campaigns', [NotificationController::class, 'storeCampaign'])
        ->middleware('authorize:notification:manage');
    Route::get('campaigns/{id}', [NotificationController::class, 'showCampaign'])
        ->middleware('authorize:notification:view');
    Route::post('campaigns/{id}/{action}', [NotificationController::class, 'transitionCampaign'])
        ->middleware('authorize:notification:manage');

    // Delivery Tracking
    Route::get('campaigns/{id}/delivery', [NotificationController::class, 'campaignDelivery'])
        ->middleware('authorize:notification:view');
    Route::post('deliveries/{attemptId}/acknowledge', [NotificationController::class, 'acknowledgeDelivery'])
        ->middleware('authorize:notification:view');

    // Emergency Broadcast
    Route::post('emergency', [NotificationController::class, 'emergencyBroadcast'])
        ->middleware('authorize:notification:manage');

    // Stats
    Route::get('stats', [NotificationController::class, 'stats'])
        ->middleware('authorize:notification:view');
});

// Document center routes have been moved inside the v1 authenticated group above.
