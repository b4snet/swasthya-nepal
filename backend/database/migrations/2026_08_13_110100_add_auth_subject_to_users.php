<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — Supabase-native identity mapping (see App\\Support\\JwtClaims,
 * App\\Support\\AuthClaims).
 *
 * `users.auth_subject_id` is the application-side mapping to the Supabase
 * Auth (GoTrue) identity: the UUID of the auth.users row (the `sub` claim of
 * a GoTrue JWT). It is:
 *  - NULLABLE — legacy application accounts (created before the migration)
 *    have no GoTrue subject until they are imported;
 *  - UNIQUE (partial index on non-null) — one GoTrue identity maps to at
 *    most one application account, and one application account holds at most
 *    one subject (email/password accounts in this application);
 *  - server-managed — written only by provisioning/import code, never by
 *    client input.
 *
 * `users` is deliberately a NON-RLS-scoped identity table (like users,
 * roles, permissions — the identity catalog must be resolvable BEFORE any
 * tenant context exists), so this column adds no RLS surface: the Phase 2
 * matrix (37 on / 13 off) is unchanged.
 *
 * The claims themselves (app_user_id = users.id) never carry the subject;
 * the subject is resolved once at the auth boundary (login/import) into the
 * application user, and RLS keys off the application user's id thereafter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid('auth_subject_id')->nullable();
        });

        // Partial unique index: exactly one GoTrue subject per account, and
        // at most one account per subject. NULLs (unimported accounts) do
        // not collide.
        DB::statement('create unique index uq_users_auth_subject on users (auth_subject_id) where auth_subject_id is not null');
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('auth_subject_id');
        });
    }
};
