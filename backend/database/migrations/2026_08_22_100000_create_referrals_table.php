<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');

            // Patient
            $table->uuid('patient_id');
            $table->uuid('encounter_id')->nullable();

            // Referring provider
            $table->uuid('referring_staff_id');
            $table->string('referring_department', 100)->nullable();

            // Receiving provider/facility
            $table->uuid('receiving_staff_id')->nullable();
            $table->string('receiving_facility_name', 200)->nullable(); // external
            $table->string('receiving_department', 100)->nullable();

            // Clinical
            $table->string('reason', 500);
            $table->text('clinical_summary')->nullable();
            $table->string('urgency', 20)->default('routine'); // routine, urgent, emergent
            $table->string('specialty', 100)->nullable();
            $table->json('attachments')->nullable(); // file references

            // Lifecycle
            $table->string('status', 30)->default('pending'); // pending, accepted, rejected, scheduled, completed, cancelled
            $table->text('rejection_reason')->nullable();
            $table->text('completion_notes')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Scheduling
            $table->uuid('scheduled_appointment_id')->nullable();

            // Audit
            $table->uuid('created_by');
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            // Indexes
            $table->index(['tenant_id', 'facility_id', 'status']);
            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'referring_staff_id']);
            $table->index(['tenant_id', 'receiving_staff_id']);
        });

        // RLS
        DB::statement('ALTER TABLE referrals ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE referrals FORCE ROW LEVEL SECURITY');

        $tenantUsing = "tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid";
        $facilityUsing = "(facility_id = NULLIF(current_setting('app.facility_id', true), '')::uuid OR facility_id IS NULL)";
        $combined = "{$tenantUsing} AND {$facilityUsing}";

        DB::statement('DROP POLICY IF EXISTS p_rls_referrals_select ON referrals');
        DB::statement("CREATE POLICY p_rls_referrals_select ON referrals FOR SELECT USING ({$combined})");
        DB::statement('DROP POLICY IF EXISTS p_rls_referrals_insert ON referrals');
        DB::statement("CREATE POLICY p_rls_referrals_insert ON referrals FOR INSERT WITH CHECK ({$tenantUsing})");
        DB::statement('DROP POLICY IF EXISTS p_rls_referrals_update ON referrals');
        DB::statement("CREATE POLICY p_rls_referrals_update ON referrals FOR UPDATE USING ({$combined}) WITH CHECK ({$combined})");
        DB::statement('DROP POLICY IF EXISTS p_rls_referrals_delete ON referrals');
        DB::statement("CREATE POLICY p_rls_referrals_delete ON referrals FOR DELETE USING ({$tenantUsing})");
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
    }
};
