#!/bin/sh
# Swasthya API container entrypoint (Railway-compatible).
# Two modes:
#   * SWASTHYA_RUN_BOOTSTRAP=1 — runs database bootstrap (migrations + grants)
#   * normal start — runs PHP built-in server on PORT (default 8080)
set -eu

if [ "${SWASTHYA_RUN_BOOTSTRAP:-0}" = "1" ]; then
  BHOST="${BOOTSTRAP_DB_HOST:-${DB_HOST}}"
  BPORT="${BOOTSTRAP_DB_PORT:-${DB_PORT:-5432}}"
  BDATABASE="${BOOTSTRAP_DB_DATABASE:-${DB_DATABASE}}"
  BUSER="${BOOTSTRAP_DB_USERNAME:-${DB_USERNAME}}"
  BPASSWORD="${BOOTSTRAP_DB_PASSWORD:-${DB_PASSWORD}}"

  echo "[bootstrap] creating least-privilege role swasthya_app"
  PGPASSWORD="${BPASSWORD}" PGSSLMODE=require psql \
    -h "${BHOST}" -p "${BPORT}" -U "${BUSER}" -d "${BDATABASE}" \
    -v app_password="${DB_PASSWORD}" \
    -f database/security/roles.sql

  echo "[bootstrap] running migrations as owner (migrate --force)"
  export DB_HOST="${BHOST}" DB_PORT="${BPORT}" DB_DATABASE="${BDATABASE}" \
         DB_USERNAME="${BUSER}" DB_PASSWORD="${BPASSWORD}"
  php artisan migrate --force

  echo "[bootstrap] re-applying application grants"
  PGPASSWORD="${BPASSWORD}" PGSSLMODE=require psql \
    -h "${BHOST}" -p "${BPORT}" -U "${BUSER}" -d "${BDATABASE}" \
    -v dbname="${BDATABASE}" \
    -f database/security/grants.sql

  echo "[bootstrap] complete"
  exit 0
fi

echo "[app] starting PHP built-in server on port ${PORT:-8080}"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8080}"