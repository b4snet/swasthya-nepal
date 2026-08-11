#!/usr/bin/env bash
# ============================================================================
# Swasthya RLS load benchmark (STAGING_READINESS_REPORT.md §9).
#
#   ./load-benchmark.sh 100000
#
#  1. seeds a disposable synthetic workload at SIZE patients
#  2. benchmarks tenant-scoped queries under RLS  (swasthya_app + GUCs)
#  3. benchmarks the SAME queries with RLS disabled (owner baseline)
#  4. re-enables RLS and prints per-query timings side by side
#
# The load database is synthetic and disposable; row-level security is
# disabled ONLY there, for the controlled baseline measurement, and is
# re-enabled immediately afterwards. The real application databases are
# never touched.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/.." # backend/

SIZE="${1:-100000}"
DB="swasthya_load"
HOST=127.0.0.1
PORT=54329
OWNER=swasthya
OWNER_PW="$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2-)"
APP_ROLE_PW="$(grep -E '^RLS_DB_PASSWORD=' .env.testing | cut -d= -f2-)"

PSQL="../.toolchain/pgsql/pgsql/bin/psql.exe"
export PGPASSWORD

echo "== seeding ${SIZE} patients (synthetic, disposable) =="
PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -v size="$SIZE" -f ci/load-seed.sql | tail -1

# Pick a concrete workload row: tenant 7, facility 7 (7 % 20 = 7, 7 % 40 = 7),
# patient n=7 (7 % 20 = 7), doctor LDOC-7.
TID=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select id from organizations where code='load-org-7'")
FID=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select id from facilities where code='load-fac-7'")
SID=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select id from staff where employee_code='LDOC-7'")
PID=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select id from patients where mrn='MRN-0000000007'")
AID=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select id from appointments where tenant_id='$TID' and starts_at = (select min(starts_at) from appointments where tenant_id='$TID')")
IID=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select id from invoices where tenant_id='$TID' limit 1")
DAY=$(PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -t -A -c \
  "select date_trunc('day', starts_at) from appointments where tenant_id='$TID' and provider_staff_id='$SID' limit 1")

VARS=(-v tid="$TID" -v fid="$FID" -v sid="$SID" -v pid="$PID" -v aid="$AID" -v iid="$IID" -v day="$DAY")

echo "== benchmark: RLS mode (swasthya_app, GUCs set) =="
PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$DB" "${VARS[@]}" \
  -c "begin; select set_config('app.tenant_id', '$TID', true); select set_config('app.facility_id', '$FID', true); select set_config('app.user_id', '$TID', true); select set_config('app.is_platform', 'false', true);" \
  -f ci/load-benchmark.sql -c "commit;" 2>&1 | tee /tmp/bench_rls.txt | grep -E "Time:" > /tmp/bench_rls_times.txt || true

echo "== benchmark: baseline (owner, RLS disabled) =="
PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -c "
  alter table patients disable row level security;
  alter table appointments disable row level security;
  alter table encounters disable row level security;
  alter table diagnoses disable row level security;
  alter table prescriptions disable row level security;
  alter table prescription_lines disable row level security;
  alter table invoices disable row level security;
  alter table invoice_lines disable row level security;
  alter table payments disable row level security;
  alter table payment_allocations disable row level security;" > /dev/null

PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" "${VARS[@]}" \
  -f ci/load-benchmark.sql 2>&1 | tee /tmp/bench_base.txt | grep -E "Time:" > /tmp/bench_base_times.txt || true

echo "== re-enabling row-level security =="
PGPASSWORD="$OWNER_PW" "$PSQL" -h "$HOST" -p "$PORT" -U "$OWNER" -d "$DB" -c "
  alter table patients enable row level security;
  alter table appointments enable row level security;
  alter table encounters enable row level security;
  alter table diagnoses enable row level security;
  alter table prescriptions enable row level security;
  alter table prescription_lines enable row level security;
  alter table invoices enable row level security;
  alter table invoice_lines enable row level security;
  alter table payments enable row level security;
  alter table payment_allocations enable row level security;" > /dev/null

echo
echo "== EXPLAIN ANALYZE (RLS mode) — Q1 patient-by-id, Q2 name search, Q6 provider day =="
PGPASSWORD="$APP_ROLE_PW" "$PSQL" -h "$HOST" -p "$PORT" -U swasthya_app -d "$DB" "${VARS[@]}" -c "
  begin;
  select set_config('app.tenant_id', '$TID', true);
  select set_config('app.facility_id', '$FID', true);
  select set_config('app.user_id', '$TID', true);
  select set_config('app.is_platform', 'false', true);
  explain (analyze, buffers, costs off) select full_name, mrn from patients where id='$PID' and tenant_id='$TID';
  explain (analyze, buffers, costs off) select id, full_name from patients where tenant_id='$TID' and full_name ilike 'Load Patient 77%' order by full_name limit 20;
  explain (analyze, buffers, costs off) select id, status from appointments where tenant_id='$TID' and provider_staff_id='$SID' and starts_at >= '$DAY' and starts_at < '$DAY'::timestamptz + interval '1 day';
  commit;" 2>&1 | grep -E "QUERY PLAN|Seq Scan|Index Scan|Index Only|Bitmap|actual time|Planning Time|Execution Time|Filter|Index Cond|->  " | head -50

echo
echo "== results (ms) — per benchmark statement (RLS vs baseline) =="
# The RLS run emits one extra \"Time:\" line for the GUC-setup batch; the
# benchmark file itself emits exactly 20. Tail both to the 20 benchmark lines.
paste <(tail -20 /tmp/bench_rls_times.txt | grep -oE "[0-9.]+ ms") <(tail -20 /tmp/bench_base_times.txt | grep -oE "[0-9.]+ ms") | \
awk -F'\t' 'BEGIN{print "stmt  RLS(ms)  baseline(ms)   delta(ms)"}
{ q++; gsub(/ ms/, "", $1); gsub(/ ms/, "", $2); a=$1+0; b=$2+0; printf "S%-2d   %8.3f  %10.3f  %+9.3f\n", q, a, b, a-b }'
