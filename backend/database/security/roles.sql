-- ============================================================================
-- Swasthya database roles (SECURITY.md §14, TENANCY.md V2 §6)
-- ============================================================================
-- Creates the least-privilege APPLICATION role. Run this as the database
-- owner / migration role, OUTSIDE a transaction (CREATE ROLE is
-- non-transactional), on EVERY database the application connects to
-- (swasthya, swasthya_test, …).
--
-- Usage:
--   psql -h 127.0.0.1 -p 54329 -U <owner> -d swasthya \
--        -v app_password='<password>' -f database/security/roles.sql
--
-- Responsibilities split (TENANCY.md V2 §6):
--   * <owner> (e.g. swasthya)  — migrations, schema administration. Never
--     used by the running application.
--   * swasthya_app             — the runtime application role. Has DML grants
--     on all tables but NO ownership, NO BYPASSRLS, NO superuser: PostgreSQL
--     row-level security always applies to it (grants + RLS policies are
--     created by the row-level-security migration).
--
-- Never grant swasthya_app BYPASSRLS, ownership of tables, or DDL.
-- ============================================================================

\set ON_ERROR_STOP on

SELECT format(
    'CREATE ROLE swasthya_app LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
    :'app_password'
)
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'swasthya_app')
\gexec
