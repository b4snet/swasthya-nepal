<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 22 — Patient Portal (ROADMAP Phase 2 scope, PRODUCT
 * REQUIREMENTS §6.2, DATABASE.md §3.53).
 *
 * The patient's secure, CONSENT-BOUND window into their OWN hospital
 * record: read-only access to permitted appointments, results, and bills.
 * Strict self-only access (the patient identity is derived from the
 * authenticated portal token — never from client input), one portal
 * account per patient per tenant (login identifier unique per tenant),
 * DB-backed lockout, append-only session log (audit + revocation), and
 * per-scope access grants that staff provision and the PATIENT can revoke
 * (purpose limitation). All three tables are TENANT_FACILITY tier; RLS is
 * enabled + FORCED by the companion migration (2026_08_17_290100).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ───────────────────────── Portal accounts ─────────────────────────

        Schema::create('portal_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->string('login_identifier', 190);
            $table->text('password_hash');
            $table->text('status')->default('active'); // active | locked | disabled
            $table->integer('failed_attempts')->default(0);
            $table->timestampTz('locked_until')->nullable();
            // Reserved: MFA-optional by tenant policy (Phase 2+ enforcement,
            // PRODUCT_REQUIREMENTS §6.2). Stored now so the schema is stable.
            $table->boolean('mfa_enabled')->default(false);
            $table->timestampTz('last_login_at')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by_staff_id')->nullable();
            $table->uuid('updated_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'created_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table portal_accounts add constraint chk_portal_accounts_status check (status in ('active', 'locked', 'disabled'))");
        DB::statement('alter table portal_accounts add constraint chk_portal_accounts_attempts check (failed_attempts >= 0)');
        // One portal identity per login identifier per tenant — a patient's
        // phone/email is theirs across the tenant's facilities.
        DB::statement('create unique index uq_portal_accounts_tenant_identifier on portal_accounts (tenant_id, login_identifier)');
        // One portal account per patient per tenant.
        DB::statement('create unique index uq_portal_accounts_tenant_patient on portal_accounts (tenant_id, patient_id)');
        DB::statement('create index idx_portal_accounts_tenant_facility on portal_accounts (tenant_id, facility_id, status)');
        // Backer for the portal_sessions / portal_access_grants composite FKs.
        DB::statement('create unique index uq_portal_accounts_tenant_facility_id on portal_accounts (tenant_id, facility_id, id)');

        // ───────────────────────── Portal sessions ─────────────────────────

        Schema::create('portal_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('portal_account_id');
            $table->uuid('patient_id');
            $table->bigInteger('token_id'); // the Sanctum token row id — facts only
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revoked_by')->nullable(); // patient | staff
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'portal_account_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('portal_accounts')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();
        });

        DB::statement('create index idx_portal_sessions_tenant_account on portal_sessions (tenant_id, portal_account_id, created_at desc)');
        DB::statement('create index idx_portal_sessions_tenant_token on portal_sessions (tenant_id, token_id)');

        // ─────────────────────── Portal access grants ───────────────────────

        Schema::create('portal_access_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('portal_account_id');
            $table->uuid('patient_id');
            $table->text('data_scope'); // appointments | results | bills
            $table->text('purpose'); // purpose limitation — why this access was granted
            $table->text('status')->default('granted'); // granted | revoked
            $table->timestampTz('granted_at');
            $table->uuid('granted_by_staff_id');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by_staff_id')->nullable();
            $table->boolean('revoked_by_patient')->default(false);
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'portal_account_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('portal_accounts')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'granted_by_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        DB::statement("alter table portal_access_grants add constraint chk_portal_grants_scope check (data_scope in ('appointments', 'results', 'bills'))");
        DB::statement("alter table portal_access_grants add constraint chk_portal_grants_status check (status in ('granted', 'revoked'))");
        // One ACTIVE grant per (patient, scope) — a concurrent double-grant
        // is impossible; re-granting requires revoking first (CAS).
        DB::statement('create unique index uq_portal_grants_tenant_patient_scope on portal_access_grants (tenant_id, facility_id, patient_id, data_scope) where status = \'granted\'');
        DB::statement('create index idx_portal_grants_tenant_patient on portal_access_grants (tenant_id, patient_id, status)');
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_access_grants');
        Schema::dropIfExists('portal_sessions');
        Schema::dropIfExists('portal_accounts');
    }
};
