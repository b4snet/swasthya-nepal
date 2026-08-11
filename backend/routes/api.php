<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BedController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\EncounterController;
use App\Http\Controllers\Api\FacilityController;
use App\Http\Controllers\Api\FacilitySettingsController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsurancePolicyController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\MedicationController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PatientContactController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientDocumentController;
use App\Http\Controllers\Api\PatientIdentifierController;
use App\Http\Controllers\Api\PayerController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PlatformAssignmentController;
use App\Http\Controllers\Api\PlatformSupportController;
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

Route::middleware(['auth:sanctum', ResolveTenantContext::class])->group(function (): void {
    // Session.
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
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

    // Billing and payments.
    Route::get('invoices/{invoice}', [BillingController::class, 'showInvoice'])
        ->middleware('authorize:billing:view');
    Route::get('invoices/{invoice}/payments', [BillingController::class, 'payments'])
        ->middleware('authorize:billing:view');
    Route::post('invoices/{invoice}/pay', [BillingController::class, 'pay'])
        ->middleware('authorize:billing:collect');
});
