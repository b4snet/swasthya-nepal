<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token issuance counters (DATABASE.md §3.15: "token sequence uniqueness
 * handled by the queue — row-locked issuance"). One row per
 * (tenant, facility, provider, queue_date); check-in issues the next token
 * by locking the row, so parallel check-ins cannot mint the same number.
 *
 * Mirrors the mrn_counters pattern (DATABASE.md §3.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_counters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('provider_staff_id');
            $table->date('queue_date');
            $table->integer('last_token')->default(0);
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();

            $table->foreign(['tenant_id', 'facility_id', 'provider_staff_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('staff')
                ->restrictOnDelete();
        });

        Schema::table('token_counters', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'facility_id', 'provider_staff_id', 'queue_date'], 'uq_token_counters_tenant_facility_provider_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_counters');
    }
};
