<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patients (DATABASE.md §3.11): the master patient record — the platform's
 * most safety-critical entity. Registered once, identified reliably,
 * referenced by every module.
 *
 * Tenant-scoped (tenant_id NOT NULL, facility_id = registering facility)
 * with a tenant-safe composite FK to facilities (DATABASE.md §0.9).
 *
 * MRN is unique per tenant and NEVER reused (PRODUCT_REQUIREMENTS §6.1), so
 * uniqueness is a full index — not the partial deleted-at index §3.11 lists
 * for reuse-after-purge; if purge-with-reuse ever becomes a requirement, it
 * changes by ADR (recorded in DEVELOPMENT_LOG.md).
 *
 * No hard delete: status (active → merged/archived) is the lifecycle; merge
 * points at the survivor. `lock_version` is the optimistic-locking counter.
 *
 * `mrn_counters` is the concurrency-safe per-tenant MRN sequence: issuance
 * is an atomic `UPDATE … RETURNING` row lock, so parallel registrations
 * serialize on the counter row, never on a guess.
 *
 * pg_trgm powers patient search and duplicate detection (ROADMAP Phase 5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrn_counters', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->bigInteger('last_value')->default(0);

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        Schema::create('patients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('mrn', 50);
            $table->uuid('user_id')->nullable();
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->text('sex');
            $table->string('blood_group', 10)->nullable();
            $table->text('status')->default('active');
            $table->uuid('merge_into_patient_id')->nullable();
            $table->jsonb('consent_summary')->default('{}');
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement('create extension if not exists pg_trgm');

        DB::statement('create unique index uq_patients_tenant_mrn on patients (tenant_id, mrn)');
        DB::statement('create index idx_patients_tenant_facility on patients (tenant_id, facility_id)');
        DB::statement('create index idx_patients_tenant_dob on patients (tenant_id, date_of_birth)');
        DB::statement(
            'create index idx_patients_tenant_name_trgm on patients using gin (lower(full_name) gin_trgm_ops)'
        );
        // One live patient per portal user; merged records may keep the link
        // for history, so the constraint is active-only.
        DB::statement(
            "create unique index uq_patients_tenant_active_user on patients (tenant_id, user_id) where user_id is not null and status <> 'merged'"
        );
        // Backs the children composite FKs (identifiers, contacts, policies,
        // consents, documents, timeline).
        DB::statement('create unique index uq_patients_tenant_id_id on patients (tenant_id, id)');

        DB::statement(
            "alter table patients add constraint chk_patients_status check (status in ('active', 'merged', 'archived'))"
        );
        DB::statement(
            "alter table patients add constraint chk_patients_sex check (sex in ('male', 'female', 'other', 'unknown'))"
        );
        DB::statement(
            "alter table patients add constraint chk_patients_blood check (blood_group is null or blood_group in ('A+','A-','B+','B-','AB+','AB-','O+','O-'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
        Schema::dropIfExists('mrn_counters');
    }
};
