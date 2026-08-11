<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MFA readiness on users (DATABASE.md §3.4, MASTER_RULES.md §7.3).
 *
 * Schema only — the TOTP enrollment/verification flow lands with the MFA
 * phase; these columns exist so the schema matches the design and no later
 * migration is a surprise. Secrets are stored ENCRYPTED (column-encrypted
 * at the application layer), never plaintext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('mfa_secret_encrypted')->nullable();
            $table->jsonb('mfa_recovery_codes_encrypted')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['mfa_secret_encrypted', 'mfa_recovery_codes_encrypted']);
        });
    }
};
