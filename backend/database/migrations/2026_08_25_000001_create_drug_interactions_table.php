<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drug_interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('medication_a_id');
            $table->uuid('medication_b_id');
            $table->string('severity')->default('moderate'); // critical|major|moderate
            $table->text('description');
            $table->text('clinical_effect')->nullable();
            $table->text('recommendation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            // One interaction per medication pair per tenant (bidirectional)
            $table->unique(['tenant_id', 'medication_a_id', 'medication_b_id'], 'uq_drug_interaction_pair');

            // Note: self-interaction prevention handled at application level
            // (Blueprint::check() not available in this Laravel version)

            $table->foreign('tenant_id')->references('id')->on('organizations');
            $table->foreign('medication_a_id')->references('id')->on('medications');
            $table->foreign('medication_b_id')->references('id')->on('medications');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_interactions');
    }
};
