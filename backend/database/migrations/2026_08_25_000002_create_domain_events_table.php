<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 100);
            $table->string('aggregate_type', 50);
            $table->uuid('aggregate_id');
            $table->json('payload')->nullable();
            $table->string('causer_type', 100)->nullable();
            $table->uuid('causer_id')->nullable();
            $table->string('facility_id', 36)->nullable();
            $table->string('tenant_id', 36)->nullable();
            $table->string('correlation_id', 36)->nullable();

            // Outbox / processing state
            $table->string('status', 20)->default('pending'); // pending|processing|completed|failed|dead
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('idempotency_key', 100)->nullable();

            $table->timestamps();

            // Indexes
            $table->index('status');
            $table->index(['status', 'next_attempt_at']);
            $table->index('event_type');
            $table->index('aggregate_type');
            $table->index('aggregate_id');
            $table->index('idempotency_key');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
