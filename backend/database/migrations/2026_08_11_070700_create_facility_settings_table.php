<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facility configuration (PRODUCT_REQUIREMENTS §5.5): facility-scoped
 * settings as DATA — never code (MASTER_RULES.md §1.3, §7.2). Key/value with
 * jsonb values, versioned (every change bumps `version` and writes an audit
 * event with the old and new values).
 *
 * Tenant-scoped with a tenant-safe composite FK to facilities (DATABASE.md
 * §0.9). Never soft-deleted: removing a setting is itself a state change and
 * is audited. `updated_by` names the actor who last changed the value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('key', 100);
            $table->jsonb('value')->default('{}');
            $table->integer('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        Schema::table('facility_settings', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'facility_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_settings');
    }
};
