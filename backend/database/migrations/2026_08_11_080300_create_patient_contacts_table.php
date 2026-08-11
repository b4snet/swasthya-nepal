<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Patient contacts (DATABASE.md §3.13): phone, email, address, and
 * emergency contacts / next of kin, with primary flags and history.
 *
 * Exactly one of `value` (phone/email/emergency phone) or `address` (jsonb)
 * is set — enforced by CHECK. `contact_person` carries name/relation for
 * emergency contacts. History is preserved: a changed primary supersedes the
 * old row rather than deleting it (care continuity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('patient_id');
            $table->text('type');
            $table->text('value')->nullable();
            $table->jsonb('address')->nullable();
            $table->jsonb('contact_person')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'patient_id'])
                ->references(['tenant_id', 'id'])
                ->on('patients')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table patient_contacts add constraint chk_contacts_type check (type in ('phone', 'email', 'address', 'emergency_contact'))"
        );
        DB::statement(
            "alter table patient_contacts add constraint chk_contacts_status check (status in ('active', 'superseded'))"
        );
        // Exactly one of value / address carries the contact detail.
        DB::statement(
            'alter table patient_contacts add constraint chk_contacts_value check ((value is not null) <> (address is not null))'
        );

        // One active primary per (patient, type).
        DB::statement(
            'create unique index uq_contacts_tenant_patient_primary on patient_contacts (tenant_id, patient_id, type) where is_primary and status = \'active\''
        );
        DB::statement('create index idx_contacts_tenant_patient on patient_contacts (tenant_id, patient_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_contacts');
    }
};
