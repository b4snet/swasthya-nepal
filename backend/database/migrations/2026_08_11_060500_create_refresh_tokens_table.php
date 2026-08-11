<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rotating refresh tokens (SECURITY.md §4–5, MASTER_RULES.md §7.1).
 *
 *  - Only a SHA-256 hash of the token is stored — a database read yields no
 *    usable credential (SECURITY.md §5).
 *  - Rotation: each use revokes the current token and issues a successor in
 *    the same family. Reuse detection: presenting a revoked token revokes
 *    the entire family and is audited+alerted (SECURITY.md §4).
 *  - Short expiry (7 days default), bound to the issuing IP/user agent for
 *    anomaly detection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('family_id');
            $table->char('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('replaced_by')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        DB::statement('alter table refresh_tokens add constraint uq_refresh_tokens_hash unique (token_hash)');
        DB::statement('create index idx_refresh_tokens_user on refresh_tokens (user_id)');
        DB::statement('create index idx_refresh_tokens_family on refresh_tokens (family_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
