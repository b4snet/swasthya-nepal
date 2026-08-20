<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('onboarding_complete')->default(false);
            $table->string('onboarding_step', 64)->nullable();
            $table->jsonb('profile_data')->default('{}');
            $table->string('professional_status', 32)->default('pending');
            // professional_status: pending, submitted, verified, rejected
            $table->text('onboarding_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'onboarding_complete',
                'onboarding_step',
                'profile_data',
                'professional_status',
                'onboarding_completed_at',
            ]);
        });
    }
};
