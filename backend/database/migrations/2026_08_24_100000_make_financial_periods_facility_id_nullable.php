<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_periods', function ($table) {
            $table->uuid('facility_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('financial_periods', function ($table) {
            $table->uuid('facility_id')->nullable(false)->change();
        });
    }
};
