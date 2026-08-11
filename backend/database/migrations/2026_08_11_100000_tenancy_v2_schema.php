<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy V2 schema (TENANCY.md V2, SECURITY.md §14, DATABASE.md §3.x):
 *
 *  1. permissions.scope — every permission is classified platform / tenant /
 *     both. Platform context (outside a support session) can only exercise
 *     'platform' and 'both' permissions; tenant data permissions are
 *     'tenant'-scope only, so a platform administrator can never reach
 *     tenant business data without an explicit audited support session
 *     (TENANCY.md V2 §8).
 *
 *  2. branches — the optional operational sub-division of a facility in the
 *     tenancy hierarchy (PLATFORM → ORGANIZATION → FACILITY → BRANCH →
 *     catalog resources). branch_id is nullable on the catalog tables
 *     (departments, locations, wards, rooms, beds): existing rows stay
 *     branch-less and remain valid; branch is a grouping, not a hard
 *     authorization boundary (TENANCY.md V2 §4).
 *
 *  3. support_sessions — the ONLY mechanism through which a platform
 *     administrator may touch tenant data: explicit target tenant, reason,
 *     expiry, full audit. Sessions synthesize the read-only support_agent
 *     role; there is no "bypass everything" permission (TENANCY.md V2 §8).
 *
 *  4. audit_events.support_session_id — links every event written during a
 *     support session back to the session that authorized it (Phase 13).
 *
 * Backward compatible: additive columns/tables only; existing rows are
 * untouched (TENANCY.md V2 §11).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Permission scope.
        Schema::table('permissions', function (Blueprint $table): void {
            $table->text('scope')->default('tenant');
        });

        DB::statement(
            "alter table permissions add constraint chk_permissions_scope check (scope in ('platform', 'tenant', 'both'))"
        );

        // Platform provisioning + support administration are platform-scope.
        DB::table('permissions')->whereIn('code', ['organization:manage', 'support:manage'])->update(['scope' => 'platform']);
        // Identity/roles/audit are exercised by BOTH platform administrators
        // (platform administration) and tenant administrators (their tenant).
        DB::table('permissions')->whereIn('code', [
            'organization:view', 'user:view', 'user:create', 'role:view',
            'role:assign', 'role:revoke', 'audit:view',
        ])->update(['scope' => 'both']);
        // Everything else stays 'tenant': platform context cannot exercise
        // tenant business permissions without a support session.

        // 2. Branches.
        Schema::create('branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->string('name');
            $table->string('code', 50);
            $table->text('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->foreign(['tenant_id', 'facility_id'])
                ->references(['tenant_id', 'id'])
                ->on('facilities')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table branches add constraint chk_branches_status check (status in ('active', 'inactive'))"
        );
        // Composite-FK target: child rows reference (tenant_id, facility_id, id).
        DB::statement('create unique index uq_branches_tenant_facility_id on branches (tenant_id, facility_id, id)');
        DB::statement(
            'create unique index uq_branches_tenant_facility_code on branches (tenant_id, facility_id, code) where deleted_at is null'
        );
        DB::statement('create index idx_branches_tenant_facility on branches (tenant_id, facility_id)');

        // 2b. Optional branch assignment on the facility catalog resources.
        foreach (['departments', 'wards', 'rooms', 'beds'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->uuid('branch_id')->nullable();
                $t->foreign(['tenant_id', 'facility_id', 'branch_id'])
                    ->references(['tenant_id', 'facility_id', 'id'])
                    ->on('branches')
                    ->nullOnDelete();
            });

            DB::statement(
                "create index idx_{$table}_tenant_facility_branch on {$table} (tenant_id, facility_id, branch_id)"
            );
        }

        // locations already carries the reserved branch_id column — add the FK
        // and index now that branches exists.
        Schema::table('locations', function (Blueprint $t): void {
            $t->foreign(['tenant_id', 'facility_id', 'branch_id'])
                ->references(['tenant_id', 'facility_id', 'id'])
                ->on('branches')
                ->nullOnDelete();
        });
        DB::statement('create index idx_locations_tenant_facility_branch on locations (tenant_id, facility_id, branch_id)');

        // 3. Support sessions.
        Schema::create('support_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('organization_id');
            $table->uuid('facility_id')->nullable();
            $table->text('reason');
            $table->text('status')->default('active');
            $table->timestampTz('opened_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('ended_at')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->uuid('correlation_id');
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign('facility_id')->references('id')->on('facilities')->restrictOnDelete();
        });

        DB::statement(
            "alter table support_sessions add constraint chk_support_sessions_status check (status in ('active', 'ended', 'expired'))"
        );
        DB::statement('create index idx_support_sessions_user_status on support_sessions (user_id, status)');
        DB::statement('create index idx_support_sessions_status_expiry on support_sessions (status, expires_at)');

        // 4. Audit linkage to the authorizing support session (nullable).
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->uuid('support_session_id')->nullable();
        });
        DB::statement('create index idx_audit_events_support_session on audit_events (support_session_id)');
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('support_session_id');
        });
        Schema::dropIfExists('support_sessions');

        foreach (['beds', 'rooms', 'wards', 'departments', 'locations'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->dropForeign([$table.'_tenant_id_facility_id_branch_id_foreign']);
                $t->dropColumn('branch_id');
            });
        }

        Schema::dropIfExists('branches');

        Schema::table('permissions', function (Blueprint $table): void {
            $table->dropColumn('scope');
        });
    }
};
