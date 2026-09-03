<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-backed per-account login lockout for staff user logins (SECURITY.md §18,
 * API_CONTRACTS.md §15). Previously the failure counter and lockout window
 * lived only in the cache ('auth.failures:{email}'), which is volatile and
 * per-node. These columns persist the counter and lockout window in the
 * `users` row itself so a lockout survives restarts, cache eviction, and
 * multi-node deployments, and expiration is derived from the stored window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->addColumn('integer', 'failed_attempts')->default(0);
            $table->addColumn('timestampTz', 'locked_until')->nullable();
            $table->addColumn('timestampTz', 'last_failed_at')->nullable();
        });

        // Failed attempts can never be negative (DATABASE.md §0.5 CHECK).
        DB::statement('alter table users add constraint chk_users_failed_attempts check (failed_attempts >= 0)');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['failed_attempts', 'locked_until', 'last_failed_at']);
        });

        DB::statement('alter table users drop constraint if exists chk_users_failed_attempts');
    }
};