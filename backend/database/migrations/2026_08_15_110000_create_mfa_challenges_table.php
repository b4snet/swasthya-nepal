<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROGRAM PHASE 2 (MFA) — single-use, short-lived login challenges.
 *
 * When an account has MFA enabled, `POST auth/login` no longer issues tokens:
 * it mints a challenge that must be completed with a TOTP code or a
 * single-use recovery code at `POST auth/mfa/challenge`. The challenge is
 * one-shot (consumed_at), expires after 5 minutes, and only its SHA-256 hash
 * is stored — a database read can never leak a usable challenge.
 *
 * Like refresh_tokens, this table is deliberately NOT RLS-scoped: the login
 * flow (a public route) must create and consume challenges BEFORE any tenant
 * context exists. It holds no tenant data — only a user reference and a
 * hash. RLS is explicitly disabled so the matrix stays deterministic on any
 * host (Supabase enables RLS on new tables by default, which would otherwise
 * silently block the public login path). Grants to swasthya_app arrive via
 * the migration owner's ALTER DEFAULT PRIVILEGES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mfa_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('challenge_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'consumed_at']);
        });

        DB::statement('alter table mfa_challenges disable row level security');
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_challenges');
    }
};
