-- ============================================================================
-- Swasthya application-role grants — POST-RESTORE FIXUP
-- (SECURITY.md §14, TENANCY.md V2 §6, STAGING_READINESS_REPORT.md §11).
--
-- pg_dump does NOT preserve:
--   * cluster-level roles (recreate via database/security/roles.sql)
--   * ALTER DEFAULT PRIVILEGES (they are per-role, not per-database)
-- A plain restore therefore leaves the least-privilege swasthya_app role with
-- NO access even though the RLS policies exist — a silent recovery gap.
--
-- This script re-applies the same grants the row-level-security migration
-- grants at install time (grantApplicationPrivileges). Idempotent: run it on
-- every database the application connects to after any restore.
--
-- Usage (as the migration/owner role):
--   psql -h <host> -p <port> -U <owner> -d <restored-db> \
--        -v dbname=<restored-db> -f database/security/grants.sql
-- ============================================================================
\set ON_ERROR_STOP on

GRANT CONNECT ON DATABASE :"dbname" TO swasthya_app;
GRANT USAGE ON SCHEMA public TO swasthya_app;

DO $$
DECLARE
    t text;
BEGIN
    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
    LOOP
        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO swasthya_app', t);
    END LOOP;
END
$$;

DO $$
DECLARE
    s text;
BEGIN
    FOR s IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public'
    LOOP
        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE public.%I TO swasthya_app', s);
    END LOOP;
END
$$;

-- Future tables created by the migration role keep inheriting the grants.
ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO swasthya_app;
ALTER DEFAULT PRIVILEGES FOR ROLE CURRENT_USER IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO swasthya_app;
