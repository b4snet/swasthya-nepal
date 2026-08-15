<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5 — identity-binding integrity (see App\Support\IdentityProvisioner).
 *
 * The Phase 3 partial unique index (uq_users_auth_subject) already enforces
 * "one GoTrue subject → at most one application account" and therefore also
 * "one account → at most one subject" at INSERT time. What the index does NOT
 * prevent is a later UPDATE silently re-pointing an already-bound account at
 * a different subject. This migration adds the second, deterministic DB
 * invariant:
 *
 *   once users.auth_subject_id is non-null, it cannot be changed to a
 *   different non-null value; only an explicit unlink (→ NULL) is allowed,
 *   and even that is a deliberate, audited provisioning action.
 *
 * The trigger:
 *  - is minimal privilege (runs as the invoking role — NO security definer,
 *    no elevated grants; swasthya_app and the owner are both bound by it);
 *  - is deterministic (pure old/new comparison, no session state);
 *  - raises a plain exception, so any violation fails the enclosing
 *    transaction closed (no partial identity state).
 *
 * A soft-deleted account keeps its subject binding (the partial index is not
 * filtered on deleted_at): a retired identity's GoTrue subject is NOT
 * recyclable for a different account — fail closed. If an operator genuinely
 * needs to re-bind, the documented path is a controlled unlink (set NULL)
 * followed by a fresh bind, both audited by IdentityProvisioner.
 *
 * This migration changes NO RLS surface (users is a non-scoped identity
 * table) and NO policy — the Phase 2 matrix stays 37-on/13-off.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create or replace function public.swasthya_guard_auth_subject_rebind() returns trigger
            language plpgsql
            as $$
            begin
                if old.auth_subject_id is not null
                   and new.auth_subject_id is not null
                   and new.auth_subject_id <> old.auth_subject_id then
                    raise exception 'users.auth_subject_id is immutable once bound; unlink before rebinding';
                end if;
                return new;
            end;
            $$
            SQL);

        DB::statement(<<<'SQL'
            create trigger trg_users_auth_subject_guard
                before update on public.users
                for each row
                execute function public.swasthya_guard_auth_subject_rebind()
            SQL);
    }

    public function down(): void
    {
        DB::statement('drop trigger if exists trg_users_auth_subject_guard on public.users');
        DB::statement('drop function if exists public.swasthya_guard_auth_subject_rebind()');
    }
};
