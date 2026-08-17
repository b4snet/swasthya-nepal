<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Facility;
use App\Models\Medication;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * STAGING-ONLY synthetic fixture (STAGING.md §11, STAGING_DEPLOYMENT_REPORT).
 *
 * Creates two synthetic tenants — A ("two.test") and B ("three.test") — with
 * the minimum operational shape the E2E and isolation suites need: org →
 * facility → department → users → staff → service → doctor schedule.
 *
 * This is deliberately NOT part of DatabaseSeeder (MASTER_RULES.md P.9: no
 * demo data, no demo-only functionality). It exists only so a fresh staging
 * environment is reproducible — the dev fixture was hand-provisioned, which
 * is exactly the gap this closes. It refuses to run when APP_ENV=production
 * and must be invoked explicitly:
 *
 *   php artisan db:seed --class=StagingFixtureSeeder --force
 *
 * Run as the MIGRATION/OWNER role (migrations and seeds never run as the
 * runtime swasthya_app role — TENANCY.md V2 §6).
 *
 * The fixture password is a documented synthetic test credential (already
 * used by the E2E suite), overridable via STAGING_FIXTURE_PASSWORD. It is
 * NOT a production credential and never appears in production.
 */
class StagingFixtureSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException(
                'StagingFixtureSeeder refuses to run on production (APP_ENV=production).'
            );
        }

        $this->call(RolePermissionSeeder::class);

        $password = env('STAGING_FIXTURE_PASSWORD', 'SmokePass-2026!');

        $this->provisionTenant([
            'org' => ['name' => 'Smoke Hospital Group', 'code' => 'smoke-group'],
            'facility' => ['name' => 'Smoke Central', 'code' => 'smoke-central'],
            'department' => ['name' => 'General OPD', 'code' => 'general-opd'],
            'staff' => ['full_name' => 'Dr Smoke', 'employee_code' => 'DOC-001', 'designation' => 'General Physician'],
            // The RPM smoke surface (backend/smoke_staging.sh) enrolls devices
            // as the NURSE — device enrollment requires a staff-bound account
            // with rpm:manage (nurse is the documented enrollment role).
            'nurse' => ['full_name' => 'Nurse Smoke', 'employee_code' => 'NUR-001', 'designation' => 'Staff Nurse'],
            'assignments' => [
                ['email' => 'smoke.super@two.test', 'role' => 'superadmin', 'scope' => 'platform'],
                ['email' => 'smoke.admin@two.test', 'role' => 'org_admin', 'scope' => 'organization'],
                ['email' => 'smoke.hadmin@two.test', 'role' => 'hospital_admin', 'scope' => 'facility'],
                ['email' => 'smoke.doctor@two.test', 'role' => 'doctor', 'scope' => 'facility'],
                ['email' => 'smoke.nurse@two.test', 'role' => 'nurse', 'scope' => 'facility'],
            ],
        ], $password);

        // Tenant B — a second synthetic tenant used by the staging isolation
        // suite: cross-tenant API probes and RLS checks must show B cannot
        // reach A's data (STAGING.md §12).
        $this->provisionTenant([
            'org' => ['name' => 'Apex Care Network', 'code' => 'apex-care'],
            'facility' => ['name' => 'Apex Central', 'code' => 'apex-central'],
            'department' => ['name' => 'General OPD', 'code' => 'general-opd'],
            'staff' => ['full_name' => 'Dr Apex', 'employee_code' => 'DOC-001', 'designation' => 'General Physician'],
            'assignments' => [
                ['email' => 'smoke.hadmin@three.test', 'role' => 'hospital_admin', 'scope' => 'facility'],
                ['email' => 'smoke.doctor@three.test', 'role' => 'doctor', 'scope' => 'facility'],
            ],
        ], $password);

        $this->command?->info(
            'Staging fixture provisioned: tenants smoke-group (A) and apex-care (B).'
        );
    }

    /**
     * @param  array{
     *     org: array{name: string, code: string},
     *     facility: array{name: string, code: string},
     *     department: array{name: string, code: string},
     *     staff: array{full_name: string, employee_code: string, designation: string},
     *     nurse?: array{full_name: string, employee_code: string, designation: string}|null,
     *     assignments: list<array{email: string, role: string, scope: string}>
     * }  $shape
     */
    private function provisionTenant(array $shape, string $password): void
    {
        $org = Organization::updateOrCreate(
            ['code' => $shape['org']['code']],
            [
                'name' => $shape['org']['name'],
                'status' => Organization::STATUS_ACTIVE,
                'currency' => 'NPR',
                'timezone' => 'Asia/Kathmandu',
                'locale' => 'en',
            ]
        );

        $facility = Facility::updateOrCreate(
            ['tenant_id' => $org->id, 'code' => $shape['facility']['code']],
            [
                'name' => $shape['facility']['name'],
                'status' => Facility::STATUS_ACTIVE,
                'timezone' => 'Asia/Kathmandu',
            ]
        );

        $department = Department::updateOrCreate(
            ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => $shape['department']['code']],
            [
                'name' => $shape['department']['name'],
                'status' => Department::STATUS_ACTIVE,
            ]
        );

        $users = [];
        $doctorEmail = null;
        foreach ($shape['assignments'] as $a) {
            $user = User::updateOrCreate(
                ['email' => $a['email']],
                ['password_hash' => $password, 'status' => User::STATUS_ACTIVE]
            );
            $users[$a['email']] = $user;
            if ($a['role'] === 'doctor') {
                $doctorEmail = $a['email'];
            }

            $role = Role::where('code', $a['role'])->firstOrFail();

            RoleAssignment::firstOrCreate([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'tenant_id' => $a['scope'] === 'platform' ? null : $org->id,
                'facility_id' => $a['scope'] === 'facility' ? $facility->id : null,
                'branch_id' => null,
            ], [
                'scope_type' => $a['scope'],
                'status' => RoleAssignment::STATUS_ACTIVE,
                'granted_at' => now(),
            ]);
        }

        if ($doctorEmail === null) {
            throw new \RuntimeException("StagingFixtureSeeder: no 'doctor' assignment in shape for org {$shape['org']['code']}.");
        }
        $doctorUser = $users[$doctorEmail];

        // The clinical provider identity belongs to the DOCTOR login account
        // (the dev fixture links DOC-001 to smoke.doctor@two.test). Keyed by
        // employee code so re-seeding updates the link instead of duplicating.
        $staff = Staff::updateOrCreate(
            ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'employee_code' => $shape['staff']['employee_code']],
            [
                'department_id' => $department->id,
                'user_id' => $doctorUser->id,
                'full_name' => $shape['staff']['full_name'],
                'designation' => $shape['staff']['designation'],
                'status' => Staff::STATUS_ACTIVE,
            ]
        );

        // The RPM device-enrollment identity belongs to the NURSE login
        // account (nurse = the documented enrollment role, rpm:manage).
        // Keyed by employee code so re-seeding updates the link.
        if (isset($shape['nurse']) && is_array($shape['nurse'])) {
            $nurseEmail = collect($shape['assignments'])->firstWhere('role', 'nurse')['email'] ?? null;
            if ($nurseEmail !== null && isset($users[$nurseEmail])) {
                Staff::updateOrCreate(
                    ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'employee_code' => $shape['nurse']['employee_code']],
                    [
                        'department_id' => $department->id,
                        'user_id' => $users[$nurseEmail]->id,
                        'full_name' => $shape['nurse']['full_name'],
                        'designation' => $shape['nurse']['designation'],
                        'status' => Staff::STATUS_ACTIVE,
                    ]
                );
            }
        }

        $service = Service::updateOrCreate(
            ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => 'opd-consult'],
            [
                'name' => 'OPD Consultation',
                'service_type' => 'opd_consultation',
                'status' => Service::STATUS_ACTIVE,
                'default_charge_minor' => 5000,
            ]
        );

        // Formulary — the OPD workflow prescribes from the tenant's catalog
        // (dev fixture: para-500 Paracetamol at 3000 minor = NPR 30.00, which
        // the E2E invoice assertion expects: 5000 consultation + 3000 med).
        Medication::updateOrCreate(
            ['tenant_id' => $org->id, 'facility_id' => $facility->id, 'code' => 'para-500'],
            [
                'generic_name' => 'Paracetamol',
                'brand_name' => 'Calpol',
                'strength' => '500mg',
                'form' => 'tablet',
                'unit' => 'tab',
                'price_minor' => 3000,
                'currency' => 'NPR',
                'is_controlled' => false,
                'status' => Medication::STATUS_ACTIVE,
            ]
        );

        // Recurring Tuesday 09:00–11:00, 30-minute slots, capacity 5 —
        // the exact shape the OPD E2E expects (next-Tuesday booking).
        ScheduleTemplate::firstOrCreate(
            [
                'tenant_id' => $org->id,
                'facility_id' => $facility->id,
                'staff_id' => $staff->id,
                'day_of_week' => 2,
                'starts_at' => '09:00:00',
            ],
            [
                'service_id' => $service->id,
                'ends_at' => '11:00:00',
                'slot_minutes' => 30,
                'capacity' => 5,
                'valid_from' => today(),
                'status' => ScheduleTemplate::STATUS_ACTIVE,
            ]
        );
    }
}
