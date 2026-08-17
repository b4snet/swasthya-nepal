#!/usr/bin/env bash
# ============================================================================
# Swasthya failover-readiness drill (Phase 22 — ROADMAP Phase 22,
# DISASTER_RECOVERY.md §13, DEPLOYMENT.md §8 multi-region).
#
# Simulates database failover in the DISPOSABLE local environment:
#   0. requires a PRE-VERIFIED standby (the restore drill's output, e.g.
#      swasthya_restore_load) with schema + data + RLS intact;
#   1. simulates primary loss by switching the APPLICATION's config to the
#      standby (the app is stateless — DEPLOYMENT.md §5 — so failover is a
#      config switch, not a reconstruction);
#   2. serves REAL HTTP through `php artisan serve` against the standby and
#      proves /api/v1/health/ready (a real dependency check) returns ok;
#   3. re-verifies RLS isolation on the standby as swasthya_app with the
#      canonical request.jwt.claims payload;
#   4. records switch-over and total-failover measurements.
#
# Honest limits (recorded, not claimed away): this proves the application
# serves from a standby database in ONE environment. A production multi-
# region cutover (DNS/edge, WAL promotion, read replicas) requires real
# infrastructure and is exercised by the annual failover drill on that
# infrastructure — NOT simulated here (NATIONAL_SCALE.md "NOT PROVEN").
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/.." # backend/

STANDBY_DB="${FAILOVER_STANDBY_DB:-swasthya_restore_load}"
HOST=127.0.0.1
PORT=54329
OWNER=swasthya
OWNER_PW="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)"
APP_ROLE_PW="$(grep -E '^RLS_DB_PASSWORD=' .env.testing | cut -d= -f2-)"
PHP="${PHP:-../.toolchain/php/php.exe}"
PSQL="../.toolchain/pgsql/pgsql/bin/psql.exe"

step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }

step "0/5 standby preconditions ($STANDBY_DB)"
export PGPASSWORD="$OWNER_PW"
TABLES=$("$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$STANDBY_DB" -t -A -c \
  "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE'")
PATIENTS=$("$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$STANDBY_DB" -t -A -c \
  "select count(*) from patients")
POLICIES=$("$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$STANDBY_DB" -t -A -c \
  "select count(*) from pg_policies where schemaname='public'")
echo "standby $STANDBY_DB: $TABLES tables, $PATIENTS patients, $POLICIES policies"
[ "$TABLES" -gt 100 ] || { echo "FAIL: standby schema missing"; exit 1; }

step "1/5 simulate primary loss — switch the app config to the standby"
SWITCH_START=$(date +%s)
DB_DATABASE="$STANDBY_DB" "$PHP" artisan migrate:status > /dev/null
SWITCH_END=$(date +%s)
echo "config switch + schema verification: $((SWITCH_END - SWITCH_START))s"

step "2/5 serve real HTTP against the standby and probe readiness"
# Pick a free loopback port (the app is served from the standby's DB).
HTTP_PORT="${FAILOVER_HTTP_PORT:-}"
if [ -z "$HTTP_PORT" ]; then
  for p in 59991 59992 59993 59994 59995; do
    if ! (exec 3<>"/dev/tcp/127.0.0.1/$p") 2>/dev/null; then HTTP_PORT="$p"; break; fi
  done
  HTTP_PORT="${HTTP_PORT:-59991}"
fi
DB_DATABASE="$STANDBY_DB" "$PHP" artisan serve --host=127.0.0.1 --port="$HTTP_PORT" \
  > storage/logs/failover-serve.log 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT
for _ in $(seq 1 30); do
  if curl -sf "http://127.0.0.1:$HTTP_PORT/api/v1/health/live" > /dev/null 2>&1; then break; fi
  sleep 1
done
LIVE=$(curl -sf "http://127.0.0.1:$HTTP_PORT/api/v1/health/live")
READY=$(curl -sf "http://127.0.0.1:$HTTP_PORT/api/v1/health/ready")
echo "live:  $LIVE"
echo "ready: $READY"
echo "$READY" | grep -q '"status":"ok"' || { echo "FAIL: readiness check not ok against standby"; exit 1; }

step "3/5 RLS isolation on the standby (swasthya_app, canonical claims)"
IFS='|' read -r TID FID PID < <("$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$STANDBY_DB" -t -A -c \
  "select tenant_id, facility_id, id from patients limit 1")
PID="${PID%$'\r'}"
echo "tenant=$TID facility=$FID patient=$PID"
echo "-- with context (should be 1)"
WITH_CTX=$(PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$STANDBY_DB" -t -A -c \
  "begin; select set_config('request.jwt.claims', json_build_object('app_user_id','','app_tenant_id','$TID','app_facility_id','$FID','app_branch_id','','app_is_platform','false')::text, true); select count(*) from patients where id='$PID'; commit;" | grep -E '^[01]$' | tr -d '\r' | tail -1)
echo "with-context count: $WITH_CTX"
echo "-- wrong tenant (should be 0)"
WRONG_CTX=$(PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$STANDBY_DB" -t -A -c \
  "begin; select set_config('request.jwt.claims', json_build_object('app_user_id','','app_tenant_id',gen_random_uuid()::text,'app_facility_id','','app_branch_id','','app_is_platform','false')::text, true); select count(*) from patients where id='$PID'; commit;" | grep -E '^[01]$' | tr -d '\r' | tail -1)
echo "wrong-tenant count: $WRONG_CTX"
[ "$WITH_CTX" = 1 ] || { echo "FAIL: RLS with-context must see the row"; exit 1; }
[ "$WRONG_CTX" = 0 ] || { echo "FAIL: RLS wrong-tenant must see nothing"; exit 1; }

step "4/5 record measurements"
echo "switch-over (config + schema verify): $((SWITCH_END - SWITCH_START))s"
echo "readiness probe against standby: ok"
echo "FAILOVER DRILL COMPLETE — application serves from standby; RLS intact."
echo "NOTE: single-environment proof. Production multi-region cutover requires real"
echo "infrastructure and the annual failover exercise (DISASTER_RECOVERY.md §13)."
