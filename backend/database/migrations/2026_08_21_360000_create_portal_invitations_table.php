<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('portal_account_id');
            $table->uuid('patient_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('portal_account_id')->references('id')->on('portal_accounts')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            // Invitation
            $table->string('invitation_token', 100)->unique()->comment('Secure random token for activation link');
            $table->string('email', 255)->nullable()->comment('Invitation sent to this email');
            $table->string('phone', 50)->nullable()->comment('Invitation sent to this phone');
            $table->string('status', 20)->default('pending')->comment('pending|accepted|expired|revoked');

            // Expiry
            $table->timestamp('expires_at')->comment('Token expires after this time');
            $table->timestamp('accepted_at')->nullable()->comment('When the patient activated');
            $table->timestamp('revoked_at')->nullable()->comment('When the invitation was revoked');
            $table->uuid('sent_by_staff_id')->nullable()->comment('Staff member who sent the invitation');

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['invitation_token'], 'idx_portal_invitation_token_unique');
        });

        // RLS: tenant isolation
        DB::statement('ALTER TABLE public.portal_invitations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE public.portal_invitations FORCE ROW LEVEL SECURITY');

        DB::statement('
            CREATE POLICY p_rls_portal_invitations ON public.portal_invitations
            USING (
                swasthya_rls_is_platform() = true
                OR tenant_id = swasthya_rls_tenant_id()
            )
            WITH CHECK (
                swasthya_rls_is_platform() = true
                OR tenant_id = swasthya_rls_tenant_id()
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_invitations');
    }
};
