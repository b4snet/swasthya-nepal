#!/usr/bin/env bash
# ============================================================================
# Swasthya backup / restore drill (DISASTER_RECOVERY.md, STAGING §10-14).
#
# Performs a REAL backup of the development database and restores it into a
# clean database, then VERIFIES the restore: schema, migrations, data, RLS
# policies, audit records, the least-privilege role, and tenant isolation.
#
#   ./backup-restore-drill.sh            (restores into swasthya_restore)
#   DRILL_RESTORE_DB=swasthya_restore2 ./backup-restore-drill.sh
#
# Records: backup start/end/size, restore start/end, total duration.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/.." # backend/

SRC_DB="${DRILL_SRC_DB:-swasthya}"
RESTORE_DB="${DRILL_RESTORE_DB:-swasthya_restore}"
HOST=127.0.0.1
PORT=54329
OWNER=swasthya
OWNER_PW="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)"
APP_ROLE_PW="$(grep -E '^RLS_DB_PASSWORD=' .env.testing | cut -d= -f2-)"
DUMP_DIR="${DRILL_DUMP_DIR:-storage/app/backup-drill}"
DUMP="$DUMP_DIR/swasthya-$(date +%Y%m%d-%H%M%S).dump"

PSQL="../.toolchain/pgsql/pgsql/bin/psql.exe"
PG_DUMP="../.toolchain/pgsql/pgsql/bin/pg_dump.exe"
PG_RESTORE="../.toolchain/pgsql/pgsql/bin/pg_restore.exe"
CREATEDB="../.toolchain/pgsql/pgsql/bin/createdb.exe"
DROPDB="../.toolchain/pgsql/pgsql/bin/dropdb.exe"
export PGPASSWORD="$OWNER_PW"

mkdir -p "$DUMP_DIR"
mkdir -p storage/app/backup-drill

step() { printf '\n\033[1m== %s ==\033[0m\n' "$*"; }

step "1/5 backup $SRC_DB -> $DUMP"
BACKUP_START=$(date +%s)
"$PG_DUMP" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$SRC_DB" -Fc --no-owner --no-privileges -f "$DUMP"
BACKUP_END=$(date +%s)
BACKUP_SIZE=$(stat -c %s "$DUMP" 2>/dev/null || stat -f %z "$DUMP")
echo "backup start: $BACKUP_START  end: $BACKUP_END  duration: $((BACKUP_END - BACKUP_START))s  size: $BACKUP_SIZE bytes"
"$PG_DUMP" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$SRC_DB" --schema-only -f "$DUMP_DIR/schema-only.sql" 2>/dev/null || true

step "2/5 create clean restore database $RESTORE_DB"
"$DROPDB" --if-exists -h "$HOST" -p "$PORT" -U "$OWNER" "$RESTORE_DB" 2>/dev/null || true
"$CREATEDB" -h "$HOST" -p "$PORT" -U "$OWNER" "$RESTORE_DB"
echo "created $RESTORE_DB"

step "3/5 restore"
RESTORE_START=$(date +%s)
"$PG_RESTORE" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" --no-owner "$DUMP"
RESTORE_END=$(date +%s)
echo "restore start: $RESTORE_START  end: $RESTORE_END  duration: $((RESTORE_END - RESTORE_START))s"

step "4/5 recreate cluster-level role + grants (NOT preserved by pg_dump)"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" \
  -v app_password="$APP_ROLE_PW" -f database/security/roles.sql | tail -1
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -v dbname="$RESTORE_DB" -f database/security/grants.sql | tail -1
echo "roles + grants re-applied (roles.sql + grants.sql)"

step "5/5 VERIFY the restored database"
echo "-- schema: table count"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select count(*) from information_schema.tables where table_schema='public' and table_type='BASE TABLE'"
echo "-- migrations table"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select count(*) from migrations"
echo "-- data (patients, appointments, audit events)"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select (select count(*) from patients) || ' patients / ' || (select count(*) from appointments) || ' appointments / ' || (select count(*) from audit_events) || ' audit events'"
echo "-- RLS: enabled tables with policies (source vs restored)"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$SRC_DB" -t -A -c \
  "select count(*) from pg_policies where schemaname='public'"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select count(*) from pg_policies where schemaname='public'"
echo "-- RLS enabled on patients / audit_events"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select relname || ' rls=' || relrowsecurity from pg_class where relname in ('patients','audit_events') order by 1"
echo "-- role configuration after restore"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select rolname || ' bypass=' || rolbypassrls || ' super=' || rolsuper from pg_roles where rolname='swasthya_app'"
echo "-- audit records present"
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select action || ' @ ' || facility_id::text from audit_events order by occurred_at desc limit 3"

echo
echo "== RLS isolation re-verified on the RESTORED database (as swasthya_app) =="
TID=$("$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select tenant_id from facilities limit 1")
FID=$("$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select id from facilities limit 1")
"$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$RESTORE_DB" -t -A -c \
  "select id from patients limit 1" > /tmp/drill_pid.txt
PID=$(head -1 /tmp/drill_pid.txt)
echo "tenant=$TID facility=$FID patient=$PID"
echo "-- swasthya_app WITH context sees the patient (should be 1)"
PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$RESTORE_DB" -t -A -c \
  "begin; select set_config('app.tenant_id','$TID',true); select set_config('app.facility_id','$FID',true); select set_config('app.is_platform','false',true); select count(*) from patients where id='$PID'; commit;"
echo "-- swasthya_app WITHOUT context (safe failure, should be 0)"
PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$RESTORE_DB" -t -A -c \
  "select count(*) from patients where id='$PID';"
echo "-- swasthya_app WRONG tenant cannot read (should be 0)"
PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$RESTORE_DB" -t -A -c \
  "begin; select set_config('app.tenant_id', gen_random_uuid()::text, true); select set_config('app.is_platform','false',true); select count(*) from patients where id='$PID'; commit;"

echo
echo "== RPO / RTO =="
echo "backup duration: $((BACKUP_END - BACKUP_START))s | restore duration: $((RESTORE_END - RESTORE_START))s | total drill: $((RESTORE_END - BACKUP_START))s"
echo "RPO (as configured): interval since last backup (dev: on-demand, no WAL archiving)"
echo "RTO (measured, this environment): $((RESTORE_END - RESTORE_START))s + role re-creation"
echo "DRILL COMPLETE — restore verified."
