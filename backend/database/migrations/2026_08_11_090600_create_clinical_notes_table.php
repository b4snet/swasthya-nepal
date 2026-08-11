<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clinical notes (DATABASE.md §3.19): structured clinical documentation.
 * Amendments are new, audited versions (parent_note_id chain); signed notes
 * are immutable.
 *
 * Tenant-scoped; the encounter is the facility anchor. author_staff_id is
 * the clinician who wrote it (the encounter provider for consultations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('encounter_id')->nullable();
            $table->text('note_type')->default('consultation'); // consultation, nursing, procedure, progress, discharge, other
            $table->uuid('author_staff_id');
            $table->jsonb('content'); // structured sections
            $table->text('status')->default('draft'); // draft, signed, amended
            $table->timestampTz('signed_at')->nullable();
            $table->uuid('parent_note_id')->nullable(); // amendment chain
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'encounter_id'])
                ->references(['tenant_id', 'id'])
                ->on('encounters')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'author_staff_id'])
                ->references(['tenant_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        // Self-referencing amendment chain — added after the table (and its
        // primary key) exist; PostgreSQL rejects a deferred alter that
        // references a key created later in the same migration.
        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->foreign('parent_note_id')
                ->references('id')
                ->on('clinical_notes')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table clinical_notes add constraint chk_clinical_notes_type check (note_type in ('consultation', 'nursing', 'procedure', 'progress', 'discharge', 'other'))"
        );
        DB::statement(
            "alter table clinical_notes add constraint chk_clinical_notes_status check (status in ('draft', 'signed', 'amended'))"
        );

        DB::statement('create index idx_clinical_notes_tenant_encounter on clinical_notes (tenant_id, encounter_id, created_at)');
        DB::statement('create index idx_clinical_notes_tenant_author on clinical_notes (tenant_id, author_staff_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_notes');
    }
};
