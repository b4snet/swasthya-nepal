<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OPD encounter vitals:
 *  1. Add encounter_id to nursing_vitals for OPD encounter-scoped observations.
 *  2. Add encounter-scoped indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nursing_vitals', function (Blueprint $table): void {
            $table->uuid('encounter_id')->nullable()->after('admission_id');
        });

        DB::statement('CREATE INDEX idx_nursing_vitals_encounter ON nursing_vitals (encounter_id) WHERE encounter_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_nursing_vitals_encounter');
        Schema::table('nursing_vitals', function (Blueprint $table): void {
            $table->dropColumn('encounter_id');
        });
    }
};
