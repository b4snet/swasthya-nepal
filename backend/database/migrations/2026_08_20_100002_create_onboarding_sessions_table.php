<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('onboarding_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable();
            $table->uuid('facility_id')->nullable();
            $table->uuid('created_by');
            $table->string('status', 32)->default('draft');
            $table->integer('current_step')->default(1);
            $table->integer('total_steps')->default(5);
            $table->jsonb('step_data')->default('{}');
            $table->jsonb('selected_modules')->default('[]');
            $table->jsonb('module_configurations')->default('{}');
            $table->jsonb('organization_data')->default('{}');
            $table->jsonb('facility_data')->default('{}');
            $table->text('activated_at')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_sessions');
    }
};
