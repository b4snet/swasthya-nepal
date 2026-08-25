<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->string('department')->index();
            $t->string('queue_code')->unique();
            $t->uuid('patient_id')->index();
            $t->uuid('appointment_id')->nullable()->index();
            $t->uuid('provider_staff_id')->nullable()->index();
            $t->string('priority')->default('normal');
            $t->string('status')->default('waiting');
            $t->integer('token_number')->nullable();
            $t->timestamp('called_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('waiting_room')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('resource_bookings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->string('resource_type')->index();
            $t->uuid('resource_id')->index();
            $t->string('booking_code')->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->uuid('patient_id')->nullable()->index();
            $t->uuid('encounter_id')->nullable();
            $t->uuid('appointment_id')->nullable()->index();
            $t->uuid('provider_staff_id')->nullable()->index();
            $t->timestamp('starts_at')->index();
            $t->timestamp('ends_at')->index();
            $t->string('status')->default('reserved');
            $t->text('notes')->nullable();
            $t->uuid('prepared_by')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();

            $t->index(['resource_type', 'resource_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_bookings');
        Schema::dropIfExists('queue_entries');
    }
};
