<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_acknowledgments', function (Blueprint $table): void {
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::table('report_template_versions', function (Blueprint $table): void {
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::table('report_template_versions', function (Blueprint $table): void {
            $table->dropColumn('updated_at');
        });

        Schema::table('report_acknowledgments', function (Blueprint $table): void {
            $table->dropColumn(['created_at', 'updated_at']);
        });
    }
};
