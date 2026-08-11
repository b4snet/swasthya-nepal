<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient identifiers (DATABASE.md §3.12): national ID (e.g., NPRN where
 * applicable), passport, driving license, other — for identity verification
 * and consent-based national linkage.
 *
 * `value_encrypted` holds ciphertext at rest (app-layer EncryptedString
 * cast); `value_hash` is a deterministic sha256 of the normalized value used
 * for duplicate DETECTION — a collision surfaces merge candidates, never an
 * auto-merge (DATABASE.md §3.12). One active identifier per (patient, type);
 * superseded by status, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_identifiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id');
            $table->text('type');
            $table->text('value_encrypted');
            $table->char('value_hash', 64);
            $table->string('issuing_country', 100)->nullable();
            $table->boolean('is_verified')->default(false);
            $table->uuid('verified_by')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();

            $table->foreign('verified_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table patient_identifiers add constraint chk_identifiers_type check (type in ('national_id', 'passport', 'license', 'other'))"
        );
        DB::statement(
            "alter table patient_identifiers add constraint chk_identifiers_status check (status in ('active', 'superseded'))"
        );

        // One active identifier per (patient, type).
        DB::statement(
            'create unique index uq_identifiers_tenant_patient_type on patient_identifiers (tenant_id, patient_id, type) where status = \'active\''
        );
        // Deterministic-hash duplicate detection across the tenant. This is
        // deliberately NOT unique: a second registration carrying the same
        // national ID must be allowed so duplicate candidates are surfaced
        // and merged by staff — never silently rejected (DATABASE.md §3.12).
        DB::statement(
            'create index idx_identifiers_tenant_type_hash on patient_identifiers (tenant_id, type, value_hash) where status = \'active\''
        );
        DB::statement('create index idx_identifiers_tenant_patient on patient_identifiers (tenant_id, patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_identifiers');
    }
};
