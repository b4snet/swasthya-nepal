<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('inventory_movements', function (Blueprint $table) {
            if (! Schema::connection('pgsql')->hasColumn('inventory_movements', 'wastage_id')) {
                $table->uuid('wastage_id')->nullable()->after('dispensing_id');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn('wastage_id');
        });
    }
};
