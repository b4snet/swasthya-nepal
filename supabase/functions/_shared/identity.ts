/**
 * Application identity lookup for Edge Functions.
 *
 * Contract: the GoTrue `sub` (the auth.users.id) is mapped to the application
 * account via `users.auth_subject_id` (Phase 3 migration
 * 2026_08_13_110100_add_auth_subject_to_users). The mapping is server-side
 * only — the client can never supply an application user id.
 *
 * The lookup itself is injected so the module stays pure (executable in the
 * local harness); the deployed function wires it to a PostgreSQL query
 * (auth.users.id = users.auth_subject_id). The result is the authoritative
 * application identity whose ACTIVE role assignments drive context
 * resolution — a GoTrue subject with no linked application account resolves
 * to null and is refused (controlled 401), never auto-provisioned.
 *
 * Phase 5 hardening: the subject is ALSO shape-checked. GoTrue `sub` is the
 * UUID of auth.users.id, and the application column users.auth_subject_id is
 * uuid-typed — a non-UUID subject can never map, so it is refused up front
 * (fail closed) instead of being handed to the lookup.
 */
import type { AppUser } from './types.ts';

export type FindUserBySubject = (sub: string) => AppUser | null;

/** GoTrue `sub` values are UUIDs (auth.users.id). */
export function isUuid(value: string): boolean {
  return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
}

export function resolveAppUser(sub: string, findUserBySubject: FindUserBySubject): AppUser | null {
  if (sub === '' || !isUuid(sub)) return null;
  return findUserBySubject(sub);
}
