<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_numbers', function (Blueprint $table) {
            $table->string('reset_key', 20)->nullable()->after('facility_id');
            $table->index(['tenant_id', 'document_type', 'reset_key']);
        });
    }

    public function down(): void
    {
        Schema::table('document_numbers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'document_type', 'reset_key']);
            $table->dropColumn('reset_key');
        });
    }
};
