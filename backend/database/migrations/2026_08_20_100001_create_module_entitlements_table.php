<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('facility_id')->nullable();
            $table->uuid('module_id');
            $table->string('status', 32)->default('disabled');
            $table->string('activation_state', 32)->default('pending');
            $table->jsonb('configuration')->default('{}');
            $table->jsonb('internal_commercial')->default('{}');
            $table->text('activated_at')->nullable();
            $table->text('expires_at')->nullable();
            $table->string('source', 64)->default('onboarding');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'facility_id', 'module_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_entitlements');
    }
};
