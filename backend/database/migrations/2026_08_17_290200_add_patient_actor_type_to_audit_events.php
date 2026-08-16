<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 22 — Patient Portal (PRODUCT REQUIREMENTS §6.2, DATABASE.md
 * §3.53): patient-portal principals are audited like any other actor, with
 * the patient identity (audit_events.actor_type = 'patient'). The original
 * CHECK (2026_08_11_060400) allowed only user/system/integration; extend it
 * so portal login/revocation/disablement events are recordable. The actor
 * is the portal account id; the actor_email is the login identifier —
 * never the password, never clinical content.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table audit_events drop constraint chk_audit_events_actor_type');
        DB::statement(
            'alter table audit_events add constraint chk_audit_events_actor_type '
            ."check (actor_type in ('user', 'system', 'integration', 'patient'))"
        );
    }

    public function down(): void
    {
        DB::statement('alter table audit_events drop constraint chk_audit_events_actor_type');
        DB::statement(
            'alter table audit_events add constraint chk_audit_events_actor_type '
            ."check (actor_type in ('user', 'system', 'integration'))"
        );
    }
};
