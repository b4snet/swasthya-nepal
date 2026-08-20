<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nursing_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('assigned_to')->nullable();
            $table->string('task_type', 50);
            $table->text('description');
            $table->string('priority', 20)->default('routine');
            $table->string('status', 30)->default('pending');
            $table->timestampTz('due_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->text('completion_notes')->nullable();
            $table->integer('lock_version')->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'assigned_to', 'status']);
        });

        Schema::create('nursing_vitals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('recorded_by');
            $table->decimal('temperature_celsius', 5, 1)->nullable();
            $table->integer('heart_rate_bpm')->nullable();
            $table->integer('respiratory_rate')->nullable();
            $table->integer('systolic_bp')->nullable();
            $table->integer('diastolic_bp')->nullable();
            $table->decimal('spo2_percent', 5, 1)->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->integer('pain_score')->nullable();
            $table->integer('gcs_score')->nullable();
            $table->text('notes')->nullable();
            $table->timestampTz('observed_at');
            $table->timestampsTz();
            $table->index(['tenant_id', 'patient_id', 'observed_at']);
        });

        Schema::create('care_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('admission_id')->nullable();
            $table->uuid('created_by');
            $table->string('diagnosis', 255);
            $table->text('goals');
            $table->text('interventions');
            $table->string('status', 30)->default('active');
            $table->text('evaluation')->nullable();
            $table->uuid('revised_by')->nullable();
            $table->text('revision_reason')->nullable();
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_until')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'patient_id', 'status']);
        });

        Schema::create('shift_handovers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('ward_id')->nullable();
            $table->uuid('outgoing_staff_id');
            $table->uuid('incoming_staff_id');
            $table->string('shift', 20);
            $table->date('handover_date');
            $table->text('patient_summaries');
            $table->text('critical_items')->nullable();
            $table->text('pending_tasks')->nullable();
            $table->text('equipment_issues')->nullable();
            $table->string('status', 20)->default('draft');
            $table->uuid('accepted_by')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'facility_id', 'handover_date']);
        });

        Schema::create('nursing_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('patient_id');
            $table->uuid('alert_from')->nullable();
            $table->uuid('alert_to');
            $table->string('alert_type', 50);
            $table->string('severity', 20)->default('info');
            $table->text('message');
            $table->string('status', 20)->default('unread');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'alert_to', 'status']);
        });

        $rls = 'swasthya_rls_is_platform() = true OR tenant_id::text = swasthya_rls_tenant_id()::text';
        foreach (['nursing_tasks', 'nursing_vitals', 'care_plans', 'shift_handovers', 'nursing_alerts'] as $tbl) {
            DB::statement('ALTER TABLE '.$tbl.' ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE '.$tbl.' FORCE ROW LEVEL SECURITY');
        }
        foreach (['nursing_tasks', 'nursing_vitals', 'care_plans', 'shift_handovers', 'nursing_alerts'] as $tbl) {
            DB::statement('CREATE POLICY p_rls_'.$tbl.'_select ON public.'.$tbl.' FOR SELECT USING ('.$rls.')');
            DB::statement('CREATE POLICY p_rls_'.$tbl.'_insert ON public.'.$tbl.' FOR INSERT WITH CHECK ('.$rls.')');
            DB::statement('CREATE POLICY p_rls_'.$tbl.'_update ON public.'.$tbl.' FOR UPDATE USING ('.$rls.')');
            DB::statement('CREATE POLICY p_rls_'.$tbl.'_delete ON public.'.$tbl.' FOR DELETE USING ('.$rls.')');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nursing_alerts');
        Schema::dropIfExists('shift_handovers');
        Schema::dropIfExists('care_plans');
        Schema::dropIfExists('nursing_vitals');
        Schema::dropIfExists('nursing_tasks');
    }
};
