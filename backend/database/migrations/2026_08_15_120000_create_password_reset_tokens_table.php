<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROGRAM PHASE 2 (password reset, SECURITY.md §5) — single-use, short-lived
 * reset tokens.
 *
 * Only the SHA-256 hash of a token is stored; the plaintext travels to the
 * account owner's email exactly once. Tokens are consumed on use (consumed_at)
 * and expire after 15 minutes.
 *
 * Like mfa_challenges and refresh_tokens, this table is deliberately NOT
 * RLS-scoped: the password-reset flow is a public route that must create and
 * consume tokens BEFORE any tenant context exists. It holds no tenant data —
 * only a user reference and a hash. RLS is explicitly disabled so the matrix
 * stays deterministic on any host (Supabase enables RLS on new tables by
 * default, which would otherwise silently block the public reset path).
 * Grants to swasthya_app arrive via the migration owner's ALTER DEFAULT
 * PRIVILEGES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'consumed_at']);
        });

        DB::statement('alter table password_reset_tokens disable row level security');
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
