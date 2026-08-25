<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospital_policies', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->string('policy_code')->unique();
            $t->string('title');
            $t->string('category');
            $t->uuid('owner_staff_id')->nullable();
            $t->integer('version')->default(1);
            $t->json('content')->nullable();
            $t->date('effective_date')->nullable();
            $t->date('review_date')->nullable();
            $t->string('status')->default('draft');
            $t->uuid('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('hospital_incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->string('incident_code')->unique();
            $t->string('title');
            $t->string('category');
            $t->string('severity')->default('medium');
            $t->string('status')->default('reported');
            $t->json('description')->nullable();
            $t->uuid('reported_by')->nullable();
            $t->timestamp('reported_at')->nullable();
            $t->uuid('assigned_to')->nullable();
            $t->uuid('patient_id')->nullable()->index();
            $t->uuid('encounter_id')->nullable();
            $t->text('root_cause')->nullable();
            $t->json('contributing_factors')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('corrective_actions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->uuid('incident_id')->nullable()->index();
            $t->uuid('compliance_report_id')->nullable()->index();
            $t->string('action_code');
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('action_type')->default('corrective');
            $t->uuid('owner_staff_id')->nullable();
            $t->date('due_date')->nullable();
            $t->date('completed_date')->nullable();
            $t->uuid('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('status')->default('open');
            $t->json('evidence')->nullable();
            $t->timestamps();
        });

        Schema::create('staff_credentials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->uuid('staff_id')->index();
            $t->string('credential_type');
            $t->string('credential_code')->nullable();
            $t->string('title');
            $t->string('issuing_authority')->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('status')->default('active');
            $t->uuid('document_id')->nullable();
            $t->uuid('verified_by')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('patient_complaints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->string('complaint_code')->unique();
            $t->uuid('patient_id')->nullable()->index();
            $t->string('category');
            $t->string('title');
            $t->json('description')->nullable();
            $t->string('severity')->default('medium');
            $t->string('status')->default('submitted');
            $t->uuid('assigned_to')->nullable();
            $t->json('response')->nullable();
            $t->uuid('responded_by')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('disclosure_logs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id')->index();
            $t->uuid('facility_id')->nullable()->index();
            $t->uuid('patient_id')->nullable()->index();
            $t->string('requester_name');
            $t->string('requester_organization')->nullable();
            $t->string('purpose');
            $t->uuid('authorized_by')->nullable();
            $t->string('recipient_name')->nullable();
            $t->string('recipient_organization')->nullable();
            $t->timestamp('disclosed_at')->nullable();
            $t->json('documents')->nullable();
            $t->string('status')->default('requested');
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disclosure_logs');
        Schema::dropIfExists('patient_complaints');
        Schema::dropIfExists('staff_credentials');
        Schema::dropIfExists('corrective_actions');
        Schema::dropIfExists('hospital_incidents');
        Schema::dropIfExists('hospital_policies');
    }
};
