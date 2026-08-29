<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignUuid('facility_id')->nullable()->after('tenant_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignUuid('facility_id')->nullable()->after('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('facility_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn('facility_id');
        });
    }
};
