# SWASTHYA PILOT DEPLOYMENT RUNBOOK

**Version**: 1.0
**Commit**: `e973da2`
**Date**: August 2026

---

## 1. PREREQUISITES

- PostgreSQL 16+ database
- PHP 8.2+ with extensions
- Node.js 18+ (frontend build)
- HTTPS-enabled domain
- Redis (queues/cache)

## 2. DATABASE SETUP

```sql
CREATE ROLE swasthya_app LOGIN PASSWORD '<password>';
GRANT CONNECT ON DATABASE <db> TO swasthya_app;
GRANT USAGE ON SCHEMA public TO swasthya_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO swasthya_app;
GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO swasthya_app;
```

## 3. APPLICATION SETUP

```bash
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache

cd ../frontend
npm ci && npm run build
```

## 4. QUEUE & CRON

```bash
php artisan queue:work --sleep=3 --tries=3
# Cron: * * * * * cd /path/backend && php artisan schedule:run
```

## 5. BACKUP

```bash
pg_dump -h <host> -U <user> -d <db> | gzip > backup-$(date +%Y%m%d).sql.gz
```

## 6. ROLLBACK

```bash
php artisan migrate:rollback --step=1
git checkout <previous-commit>
composer install --no-dev
npm ci && npm run build
php artisan config:clear && php artisan route:clear
```

## 7. HEALTH CHECK

```bash
curl https://<domain>/api/v1/health/live
```
