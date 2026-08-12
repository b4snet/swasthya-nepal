#!/bin/sh
# Swasthya API container entrypoint.
#
# Two modes:
#   * SWASTHYA_RUN_BOOTSTRAP=1 (set by the Render preDeployCommand) — runs the
#     database bootstrap with the OWNER credentials (BOOTSTRAP_DB_*): create the
#     least-privilege swasthya_app role (roles.sql), apply migrations
#     (`migrate --force`, NEVER migrate:fresh / reset / DROP — MASTER_RULES.md
#     §30), then re-apply grants (grants.sql). Any failure aborts the deploy.
#   * normal start — php-fpm + nginx for the running application.
#
# The running application ALWAYS connects as swasthya_app (NOBYPASSRLS), never
# the owner (TENANCY.md V2 §6, SECURITY.md §14). Tables are owned by the
# migration role; the app role holds DML grants only.
#  # Env contract (set by Render, never committed — STAGING.md §6):
  #   BOOTSTRAP_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD   owner creds (predeploy)
  #   DB_HOST/PORT/DATABASE/USERNAME/PASSWORD             runtime creds (swasthya_app);
  #                                                        DB_PASSWORD is ALSO the password
  #                                                        roles.sql creates swasthya_app with
  #   APP_KEY, APP_ENV, APP_DEBUG=false
set -eu

if [ "${SWASTHYA_RUN_BOOTSTRAP:-0}" = "1" ]; then
  BHOST="${BOOTSTRAP_DB_HOST:-${DB_HOST}}"
  BPORT="${BOOTSTRAP_DB_PORT:-${DB_PORT:-5432}}"
  BDATABASE="${BOOTSTRAP_DB_DATABASE:-${DB_DATABASE}}"
  BUSER="${BOOTSTRAP_DB_USERNAME:-${DB_USERNAME}}"
  BPASSWORD="${BOOTSTRAP_DB_PASSWORD:-${DB_PASSWORD}}"

  echo "[bootstrap] creating least-privilege role swasthya_app"
  # roles.sql is idempotent (WHERE NOT EXISTS); ON_ERROR_STOP aborts on failure.
  # The role is created with the RUNTIME DB_PASSWORD (same value the app uses
  # to connect) — a single generated secret, never two (SECURITY.md §14).
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

echo "[app] starting php-fpm + nginx"
php-fpm -D
exec nginx -g 'daemon off;'
