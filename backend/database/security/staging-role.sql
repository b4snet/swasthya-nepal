-- ============================================================================
-- Swasthya STAGING application role — LOCAL MIRROR on a SHARED cluster
-- (STAGING_DEPLOYMENT_REPORT §Database, STAGING.md §4).
-- ============================================================================
-- The dev and staging databases share one local PostgreSQL cluster, and the
-- canonical `swasthya_app` role is cluster-wide: its password is already
-- claimed by the dev environment. To keep environments separated on a shared
-- cluster (STAGING.md §7: staging credentials must never be development
-- credentials), the local staging mirror uses a dedicated least-privilege
-- role `swasthya_app_staging` with the exact same posture:
-- LOGIN, NOSUPERUSER, NOBYPASSRLS, NOINHERIT — never table ownership, never
-- DDL, never BYPASSRLS.
--
-- A REAL staging deployment gets its own PostgreSQL cluster and uses the
-- canonical names exactly as STAGING.md §4 documents: roles.sql (swasthya_app)
-- then grants.sql. This file exists only for the local shared-cluster mirror.
--
-- RLS policies are role-agnostic (no TO clause → apply to every role), so the
-- staging role is covered by every policy once it holds table grants.
--
-- Usage (as the migration/owner role, OUTSIDE a transaction):
--   psql -h <host> -p <port> -U <owner> -d swasthya_staging \
--        -v app_password='<staging-secret>' -f database/security/staging-role.sql
-- ============================================================================
\set ON_ERROR_STOP on

SELECT format(
    'CREATE ROLE swasthya_app_staging LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS',
    :'app_password'
)
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'swasthya_app_staging')
\gexec

GRANT CONNECT ON DATABASE swasthya_staging TO swasthya_app_staging;
GRANT USAGE ON SCHEMA public TO swasthya_app_staging;

DO $$
DECLARE
    t text;
BEGIN
    FOR t IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
    LOOP
        EXECUTE format('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.%I TO swasthya_app_staging', t);
    END LOOP;
END
$$;

DO $$
DECLARE
    s text;
BEGIN
    FOR s IN SELECT sequence_name FROM information_schema.sequences WHERE sequence_schema = 'public'
    LOOP
        EXECUTE format('GRANT USAGE, SELECT ON SEQUENCE public.%I TO swasthya_app_staging', s);
    END LOOP;
END
$$;

-- Keep future tables usable by the staging role too.
ALTER DEFAULT PRIVILEGES FOR ROLE swasthya IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO swasthya_app_staging;
ALTER DEFAULT PRIVILEGES FOR ROLE swasthya IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO swasthya_app_staging;
