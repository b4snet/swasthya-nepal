#!/usr/bin/env bash
# ============================================================================
# Swasthya local CI — run the full backend pipeline against a DISPOSABLE
# PostgreSQL database (never the developer's swasthya / swasthya_test).
#
# Mirrors .github/workflows/ci.yml locally so the pipeline can be executed
# and verified on a workstation before pushing (MASTER_RULES.md §25).
#
#   source check
#   → dependency check
#   → lint (Pint)
#   → disposable DB (dropdb + createdb)
#   → least-privilege role (roles.sql, NOBYPASSRLS)
#   → migrations (migrate:fresh)
#   → RLS policy / role verification
#   → full Pest suite (unit, integration, API, DB, RLS, isolation, audit)
#   → cleanup (disposable DB dropped; role left for cluster reuse)
#
# Fails on any step; no test is skipped or weakened to go green.
# ============================================================================
set -euo pipefail

cd "$(dirname "$0")/.." # backend/

# --- toolchain resolution (system binaries first, portable toolchain fallback)
PHP="${PHP:-$(command -v php || echo ../.toolchain/php/php.exe)}"
PSQL="${PSQL:-$(command -v psql || echo ../.toolchain/pgsql/pgsql/bin/psql.exe)}"
CREATEDB="${CREATEDB:-$(command -v createdb || echo ../.toolchain/pgsql/pgsql/bin/createdb.exe)}"
DROPDB="${DROPDB:-$(command -v dropdb || echo ../.toolchain/pgsql/pgsql/bin/dropdb.exe)}"

# --- connection (secrets come from the local .env — never from this script)
DB_HOST="${CI_DB_HOST:-127.0.0.1}"
DB_PORT="${CI_DB_PORT:-$(grep -E '^DB_PORT=' .env | cut -d= -f2-)}"
DB_USER="${CI_DB_USER:-$(grep -E '^DB_USERNAME=' .env | cut -d= -f2-)}"
DB_PASS="${CI_DB_PASS:-$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)}"
CI_DB="${CI_DB_NAME:-swasthya_ci}"
APP_ROLE_PASSWORD="${CI_APP_ROLE_PASSWORD:-$(grep -E '^RLS_DB_PASSWORD=' .env.testing | cut -d= -f2-)}"

export PGPASSWORD="$DB_PASS"

step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }

step "1/8 source check"
test -f artisan && test -f composer.json
test -d app && test -d database/migrations && test -d tests
echo "ok: Laravel skeleton + app namespace present"

step "2/8 dependency check"
"$PHP" -l artisan > /dev/null
if [ ! -d vendor ]; then
  echo "vendor/ missing — run: composer install"; exit 1
fi
echo "ok: vendor/ present"

step "3/8 static analysis / lint (Pint)"
"$PHP" vendor/bin/pint --test

step "4/8 disposable database ($CI_DB)"
"$DROPDB" --if-exists -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$CI_DB" 2>/dev/null || true
"$CREATEDB" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$CI_DB"
echo "ok: created fresh database $CI_DB"

step "5/8 least-privilege application role (NOBYPASSRLS)"
"$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$CI_DB" \
  -v app_password="$APP_ROLE_PASSWORD" -f database/security/roles.sql
"$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$CI_DB" -t -A -c \
  "select rolname || ' bypass=' || rolbypassrls || ' super=' || rolsuper from pg_roles where rolname='swasthya_app'"

step "6/8 migrations on disposable PostgreSQL"
DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$CI_DB" \
DB_USERNAME="$DB_USER" DB_PASSWORD="$DB_PASS" \
"$PHP" artisan migrate:fresh --force

step "7/8 RLS policy / role verification"
"$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$CI_DB" -t -A -c \
  "select count(*) from pg_policies where schemaname='public'"
"$PSQL" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$CI_DB" -t -A -c \
  "select distinct tablename from pg_policies where schemaname='public' and tablename in ('patients','appointments','encounters','invoices') order by 1"

step "8/8 full test suite (RLS suite connects as swasthya_app)"
set +e
DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$CI_DB" \
DB_USERNAME="$DB_USER" DB_PASSWORD="$DB_PASS" \
RLS_DB_HOST="$DB_HOST" RLS_DB_PORT="$DB_PORT" RLS_DB_DATABASE="$CI_DB" \
RLS_DB_USERNAME=swasthya_app RLS_DB_PASSWORD="$APP_ROLE_PASSWORD" \
"$PHP" vendor/bin/pest --ci
PEST_EXIT=$?
set -e
if [ "$PEST_EXIT" -ne 0 ]; then
  echo "FAIL: Pest exited $PEST_EXIT — see output above." >&2
  exit "$PEST_EXIT"
fi

step "cleanup — drop disposable database $CI_DB (role left for cluster reuse)"
"$DROPDB" -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" "$CI_DB"

echo
echo "LOCAL CI PASSED: source → lint → migrations → RLS → full suite."
