<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 slice 23 — Interoperability readiness (ROADMAP Phase 18,
 * INTEROPERABILITY.md §1–§14, DATABASE.md §3.42).
 *
 * Readiness layers ONLY — nothing here connects to, simulates, or claims a
 * live national/LIS/PACS integration (the honesty clause, INTEROPERABILITY.md
 * §13). What is implemented:
 *
 *   - The INTEGRATION REGISTRY (`integrations`) and its append-only message
 *     log (`integration_events`): what is connected, its MEASURED status
 *     (configured/active/degraded/disabled — recorded by status checks,
 *     never asserted), contract/standards/mapping versions, kill-switch,
 *     and every exchange (direction, message type, correlation, consent
 *     basis, payload reference, retry state). Idempotent outbound state
 *     machine: queued → sent → delivered/failed, retrying with CAS-guarded
 *     attempt counts (bounded budget) — the retry/idempotency readiness
 *     discipline (INTEROPERABILITY.md §7–8).
 *   - The EGRESS ALLOWLIST (`egress_allowlist`): approved outbound
 *     destinations per tenant — the SSRF guard an adapter must pass before
 *     any outbound call (INTEROPERABILITY.md §11, SECURITY.md §22).
 *   - The OAUTH2 PARTNER SURFACE (`oauth_partners` + `oauth_partner_tokens`):
 *     tenant-scoped partner registrations with scoped, short-lived,
 *     hash-at-rest access tokens (client_credentials); scopes gate the FHIR
 *     projection reads; webhook secrets enable HMAC-verified inbound
 *     webhooks (INTEROPERABILITY.md §11).
 *
 * All five tables are TENANT tier (tenant-scoped infrastructure, no
 * facility_id) — RLS enabled + FORCED by the companion migration
 * (2026_08_17_300100). Policy count added: 5 tables × 4 = 20 (448 → 468);
 * scoped matrix 113 → 118.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ─────────────────────── Integration registry ──────────────────────

        Schema::create('integrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->text('type'); // payment | sms | email | lab | pacs | fhir | hl7 | dicom | national
            $table->string('provider', 100);
            $table->jsonb('config_encrypted')->nullable(); // secrets-store references, never plaintext
            $table->text('status')->default('configured'); // configured | active | degraded | disabled
            $table->uuid('owner_staff_id')->nullable();
            $table->text('purpose'); // what data, what direction, what consent basis
            $table->string('contract_version', 50);
            $table->string('standards_version', 50)->nullable(); // e.g. FHIR R4.0.1, HL7 v2.3.1
            $table->string('mapping_version', 50)->nullable();
            $table->boolean('kill_switched')->default(false);
            $table->timestampTz('last_checked_at')->nullable();
            $table->jsonb('health')->nullable(); // MEASURED health: latency_ms, error_rate, last_error
            $table->bigInteger('lock_version')->default(0);
            $table->uuid('created_by_staff_id')->nullable();
            $table->uuid('updated_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table integrations add constraint chk_integrations_type check (type in ('payment', 'sms', 'email', 'lab', 'pacs', 'fhir', 'hl7', 'dicom', 'national'))"
        );
        DB::statement(
            "alter table integrations add constraint chk_integrations_status check (status in ('configured', 'active', 'degraded', 'disabled'))"
        );
        // One integration per (tenant, type, provider) — re-registering the
        // same provider is a 409, never a duplicate row. The tenant-safe
        // composite FK backers (integration_events, egress_allowlist).
        DB::statement('create unique index uq_integrations_tenant_type_provider on integrations (tenant_id, type, provider)');
        DB::statement('create unique index uq_integrations_tenant_id on integrations (tenant_id, id)');
        DB::statement('create index idx_integrations_tenant_status on integrations (tenant_id, status)');

        // ───────────────────────── Message log ──────────────────────────────

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id');
            $table->text('direction'); // inbound | outbound
            $table->text('message_type');
            $table->uuid('correlation_id');
            $table->text('consent_basis')->nullable(); // consent basis at the moment of exchange
            $table->jsonb('payload'); // facts + payload reference — NEVER PHI in log lines
            $table->text('status')->default('queued'); // queued | sent | delivered | failed | retrying | quarantined
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->string('mapping_version', 50)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'integration_id'])
                ->references(['tenant_id', 'id'])
                ->on('integrations')
                ->restrictOnDelete();
        });

        DB::statement(
            "alter table integration_events add constraint chk_integration_events_direction check (direction in ('inbound', 'outbound'))"
        );
        DB::statement(
            "alter table integration_events add constraint chk_integration_events_status check (status in ('queued', 'sent', 'delivered', 'failed', 'retrying', 'quarantined'))"
        );
        DB::statement('alter table integration_events add constraint chk_integration_events_attempts check (attempts >= 0)');
        DB::statement('create index idx_integration_events_tenant_integration on integration_events (tenant_id, integration_id, occurred_at desc)');
        DB::statement('create index idx_integration_events_tenant_status on integration_events (tenant_id, status)');

        // ─────────────────────── Egress allowlist ───────────────────────────

        Schema::create('egress_allowlist', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('integration_id')->nullable();
            $table->string('host', 253);
            $table->integer('port');
            $table->text('purpose');
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by_staff_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'integration_id'])
                ->references(['tenant_id', 'id'])
                ->on('integrations')
                ->restrictOnDelete();
        });

        DB::statement('alter table egress_allowlist add constraint chk_egress_allowlist_port check (port between 1 and 65535)');
        DB::statement('create unique index uq_egress_allowlist_tenant_host_port on egress_allowlist (tenant_id, host, port)');

        // ─────────────────── OAuth2 partner surface ─────────────────────────

        Schema::create('oauth_partners', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 150);
            $table->string('client_id', 64);
            $table->text('client_secret_hash'); // hash at rest — the secret itself is shown once
            $table->jsonb('scopes'); // the scopes this partner may request
            $table->text('status')->default('active'); // active | revoked
            $table->integer('token_ttl_seconds')->default(3600);
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret_hash')->nullable();
            $table->uuid('created_by_staff_id')->nullable();
            $table->bigInteger('lock_version')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        DB::statement("alter table oauth_partners add constraint chk_oauth_partners_status check (status in ('active', 'revoked'))");
        DB::statement('alter table oauth_partners add constraint chk_oauth_partners_ttl check (token_ttl_seconds between 60 and 86400)');
        // client_id is globally unique — the partner authenticates with it.
        // The tenant-safe composite FK backer for the tokens table.
        DB::statement('create unique index uq_oauth_partners_client_id on oauth_partners (client_id)');
        DB::statement('create unique index uq_oauth_partners_tenant_id on oauth_partners (tenant_id, id)');
        DB::statement('create index idx_oauth_partners_tenant_status on oauth_partners (tenant_id, status)');

        Schema::create('oauth_partner_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('oauth_partner_id');
            $table->string('token_hash', 64); // sha256 of the bearer token — never the token
            $table->jsonb('scopes'); // the scopes granted on THIS token
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'oauth_partner_id'])
                ->references(['tenant_id', 'id'])
                ->on('oauth_partners')
                ->restrictOnDelete();
        });

        DB::statement('create unique index uq_oauth_partner_tokens_hash on oauth_partner_tokens (token_hash)');
        DB::statement('create index idx_oauth_partner_tokens_tenant_partner on oauth_partner_tokens (tenant_id, oauth_partner_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_partner_tokens');
        Schema::dropIfExists('oauth_partners');
        Schema::dropIfExists('egress_allowlist');
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integrations');
    }
};
