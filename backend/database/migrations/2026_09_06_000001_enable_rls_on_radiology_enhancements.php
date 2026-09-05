<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * RLS enablement for Step 5 radiology enhancements.
 *
 * Enables + FORCES RLS on:
 * - modality_schedule_exceptions
 * - study_events
 *
 * Policies follow the standard TENANT_FACILITY pattern:
 * SELECT/INSERT/UPDATE/DELETE restricted to matching tenant_id + facility_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // modality_schedule_exceptions
        Schema::table('modality_schedule_exceptions', function ($table) {
            // Already has tenant_id, facility_id + composite FKs
        });

        // Enable RLS
        DB::statement('alter table modality_schedule_exceptions enable row level security');
        DB::statement('alter table modality_schedule_exceptions force row level security');

        // SELECT policy
        DB::statement('create policy modality_schedule_exceptions_select on modality_schedule_exceptions for select to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');

        // INSERT policy
        DB::statement('create policy modality_schedule_exceptions_insert on modality_schedule_exceptions for insert to swasthya_app with check (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');

        // UPDATE policy
        DB::statement('create policy modality_schedule_exceptions_update on modality_schedule_exceptions for update to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null)) with check (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');

        // DELETE policy
        DB::statement('create policy modality_schedule_exceptions_delete on modality_schedule_exceptions for delete to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');


        // study_events
        Schema::table('study_events', function ($table) {
            // Already has tenant_id, facility_id + composite FKs
        });

        DB::statement('alter table study_events enable row level security');
        DB::statement('alter table study_events force row level security');

        DB::statement('create policy study_events_select on study_events for select to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');

        DB::statement('create policy study_events_insert on study_events for insert to swasthya_app with check (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');

        DB::statement('create policy study_events_update on study_events for update to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null)) with check (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');

        DB::statement('create policy study_events_delete on study_events for delete to swasthya_app using (tenant_id = public.swasthya_rls_tenant_id() and (facility_id = public.swasthya_rls_facility_id() or public.swasthya_rls_facility_id() is null))');
    }

    public function down(): void
    {
        // Drop policies
        DB::statement('drop policy if exists modality_schedule_exceptions_select on modality_schedule_exceptions');
        DB::statement('drop policy if exists modality_schedule_exceptions_insert on modality_schedule_exceptions');
        DB::statement('drop policy if exists modality_schedule_exceptions_update on modality_schedule_exceptions');
        DB::statement('drop policy if exists modality_schedule_exceptions_delete on modality_schedule_exceptions');

        DB::statement('drop policy if exists study_events_select on study_events');
        DB::statement('drop policy if exists study_events_insert on study_events');
        DB::statement('drop policy if exists study_events_update on study_events');
        DB::statement('drop policy if exists study_events_delete on study_events');

        // Disable RLS
        DB::statement('alter table modality_schedule_exceptions no force row level security');
        DB::statement('alter table modality_schedule_exceptions disable row level security');

        DB::statement('alter table study_events no force row level security');
        DB::statement('alter table study_events disable row level security');
    }
};