<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff (DATABASE.md §3.10): employment/clinical identity within a tenant —
 * who the person is to the hospital (department, designation, licenses),
 * distinct from the global login account (users are global, DATABASE.md §1.3).
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities and departments
 * (DATABASE.md §0.9). user_id is a plain FK to the global users catalog.
 *
 * Never soft-deleted: departure is a status — clinical history references the
 * clinician and must persist (DATABASE.md §3.10). The license number is
 * encrypted at rest via the app-layer EncryptedString cast (SECURITY.md §12);
 * the DB column holds ciphertext only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('department_id');
            $table->uuid('user_id')->nullable();
            $table->string('employee_code', 50);
            $table->string('full_name');
            $table->text('designation')->nullable();
            $table->text('license_number_encrypted')->nullable();
            $table->text('status')->default('active');
            $table->date('hire_date')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id', 'department_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('departments')
                ->restrictOnDelete();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table staff add constraint chk_staff_status check (status in ('active', 'on_leave', 'departed'))"
        );

        DB::statement('create unique index uq_staff_tenant_employee_code on staff (tenant_id, employee_code)');
        // At most one NON-departed staff record per user per tenant — a
        // departed record may coexist with a re-hire (DATABASE.md §3.10).
        DB::statement(
            "create unique index uq_staff_tenant_active_user on staff (tenant_id, user_id) where status <> 'departed'"
        );
        DB::statement('create index idx_staff_tenant_facility on staff (tenant_id, facility_id)');
        DB::statement('create index idx_staff_tenant_department on staff (tenant_id, department_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('staff');
    }
};
