# Swasthya — Staging Operations Guide

> **Version:** 1.0 | **Phase:** 89 Staging Platform
> **Status:** Configuration-ready, local verification active

---

## Architecture Overview

```text
┌─────────────────────────────────────────────────────────┐
│                    RENDER (Staging)                      │
│                                                         │
│  ┌──────────────┐  ┌──────────────┐                    │
│  │  Frontend     │  │  Backend     │                    │
│  │  (Static SPA) │  │  (Docker)    │                    │
│  │  React/Vite   │  │  PHP 8.3-FPM │                    │
│  └──────┬───────┘  │  + Nginx     │                    │
│         │          └──────┬───────┘                    │
│         │                 │                             │
│         │    ┌────────────┘                             │
│         │    │                                          │
│  ┌──────┴────┴──────────────────────────────────┐      │
│  │              Supabase PostgreSQL 16           │      │
│  │         (Session pooler, port 5432)           │      │
│  └───────────────────────────────────────────────┘      │
└─────────────────────────────────────────────────────────┘
```

### Components

| Component | Technology | Purpose |
|-----------|-----------|---------|
| Frontend | React SPA, Vite | User interface |
| Backend | PHP 8.3-FPM + nginx | API server |
| Database | Supabase PostgreSQL 16 | Persistent storage |
| Cache | Database tables | Session and cache |
| Queue | Sync (in-process) | Background tasks |
| Storage | Local filesystem | File uploads |
| Mail | Log driver | Email notifications |

### Security Architecture

| Control | Implementation |
|---------|---------------|
| Application role | `swasthya_app` — least privilege, NOBYPASSRLS |
| RLS policies | 794 policies across 200+ tables |
| JWT claims | `request.jwt.claims` GUC for tenant isolation |
| Database access | Session pooler, TLS required |
| Secrets | Render environment variables (never committed) |
| HTTPS | Render edge termination |

---

## Local Staging Verification

### Prerequisites
- Docker Desktop
- Docker Compose v2+

### Start Local Staging

```bash
# Start PostgreSQL
docker compose -f docker-compose.staging.yml up -d db

# Wait for database to be ready
docker compose -f docker-compose.staging.yml exec db pg_isready -U swasthya

# Run migrations (as owner)
docker compose -f docker-compose.staging.yml exec \
  -e DB_USERNAME=swasthya \
  -e DB_PASSWORD=staging-dev-password \
  app php artisan migrate --force

# Seed RBAC catalog
docker compose -f docker-compose.staging.yml \
  -e DB_USERNAME=swasthya \
  -e DB_PASSWORD=staging-dev-password \
  exec app php artisan role:seed

# Seed Nepal hospital demo data
docker compose -f docker-compose.staging.yml \
  -e DB_USERNAME=swasthya \
  -e DB_PASSWORD=staging-dev-password \
  exec app php artisan nepal-hospital:seed
```

### Verify Health

```bash
curl http://localhost:8000/api/v1/health/live
# {"status":"ok","time":"2024-01-01T00:00:00Z"}

curl http://localhost:8000/api/v1/health/ready
# {"status":"ok","checks":[{"name":"database","status":"ok"}]}
```

### Stop Local Staging

```bash
docker compose -f docker-compose.staging.yml down
docker compose -f docker-compose.staging.yml down -v  # Remove data too
```

---

## Render Deployment

### Blueprint Configuration

The `render.yaml` defines two services:

1. **swasthya-api** — Docker web service (PHP-FPM + nginx)
2. **swasthya-static** — Static site (React SPA build)

### Environment Variables

Set in Render Dashboard (never committed to Git):

```
APP_ENV=staging
APP_KEY=<generated-once>
APP_DEBUG=false
APP_URL=https://swasthya-api.onrender.com
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-south-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=swasthya
DB_USERNAME=swasthya_app
DB_PASSWORD=<supabase-password>
DB_SSLMODE=require
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log
BOOTSTRAP_DB_HOST=aws-0-ap-south-1.pooler.supabase.com
BOOTSTRAP_DB_PORT=5432
BOOTSTRAP_DB_DATABASE=swasthya
BOOTSTRAP_DB_USERNAME=postgres
BOOTSTRAP_DB_PASSWORD=<supabase-owner-password>
```

### Deployment Process

```text
Git Push to main
  → Render auto-deploys
  → Pre-deploy bootstrap runs:
      1. Create swasthya_app role (roles.sql)
      2. Run migrations (migrate --force)
      3. Re-apply grants (grants.sql)
  → Frontend build:
      1. npm install
      2. VITE_API_BASE_URL=https://swasthya-api.onrender.com
      3. npm run build
  → Deploy complete
```

### Bootstrap Flow

```text
┌─────────────────────┐
│  SWASTHYA_RUN_      │
│  BOOTSTRAP=1        │
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  roles.sql          │  CREATE swasthya_app role
│  (as postgres)      │  NOSUPERUSER NOBYPASSRLS
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  php artisan        │  CREATE TABLE, ALTER TABLE,
│  migrate --force    │  CREATE POLICY, CREATE INDEX
│  (as postgres)      │  (bypasses RLS)
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  grants.sql         │  GRANT SELECT,INSERT,UPDATE,DELETE
│  (as postgres)      │  ALTER DEFAULT PRIVILEGES
│                     │  (swasthya_app gets DML only)
└─────────┬───────────┘
          │
          ▼
┌─────────────────────┐
│  php-fpm + nginx    │  Application serving as swasthya_app
│  (as swasthya_app)  │  RLS enforced on every query
└─────────────────────┘
```

---

## PostgreSQL Staging

### Connection

- **Pooler:** Supabase shared pooler (SESSION mode)
- **Port:** 5432 (IPv4, TLS)
- **Version:** PostgreSQL 16+
- **SSL:** Required (`sslmode=require`)

### Roles

| Role | Purpose | Privileges |
|------|---------|-----------|
| `postgres` | Owner | Full admin |
| `swasthya` | Migration role | DDL, DML |
| `swasthya_app` | Application runtime | DML only, NOBYPASSRLS |

### RLS

- Enabled on 200+ tables
- 794 policies enforced
- Claims-based isolation via `request.jwt.claims` GUC
- Tenant/facility/branch scoping

---

## Environment Separation

| Environment | Database | Cache | Queue | Session | Mail |
|-------------|----------|-------|-------|---------|------|
| Development | PostgreSQL local | array | sync | array | log |
| Test | PostgreSQL local | array | sync | array | array |
| Staging | Supabase PG16 | database | sync | database | log |
| Production | PostgreSQL (TBD) | TBD | TBD | TBD | TBD |

---

## Redis Decision

**Status: Not required for staging v1.**

Redis is needed when:
- Background job processing (notifications, reports, integrations)
- High-traffic session management
- Real-time feature scaling

Current staging uses:
- Database cache (adequate for staging)
- Sync queue (no background processing needed yet)
- Database sessions (adequate for staging)

**When Redis is needed:**
1. Real notifications are implemented
2. Report generation moves to background
3. Integration webhooks require queuing
4. Concurrent user load exceeds database session capacity

Redis configuration template is available in `docker-compose.staging.yml`.

---

## Object Storage Decision

**Status: Not required for staging v1.**

Current staging uses local filesystem storage for uploads.

Object storage (S3/MinIO) is needed when:
- Document upload/view workflows need persistent storage
- File downloads require signed URLs
- Cross-facility file sharing is required
- Hospital document archives need durable storage

**When object storage is added:**
1. Document center is actively used
2. Patient portal needs file access
3. Multi-facility file sharing is configured
4. Backup/recovery requires durable file storage

---

## Monitoring & Observability

**Status: Basic health endpoints active.**

Current observability:
- `/api/v1/health/live` — process liveness
- `/api/v1/health/ready` — dependency check (database)
- Structured JSON logging
- Request duration tracking

**When to add monitoring:**
1. Staging has real users
2. Error rates increase
3. Performance optimization is needed
4. Operational debugging is required

---

## Backup & Recovery

### Staging Backup

| Component | Method | Frequency |
|-----------|--------|-----------|
| Database | Supabase automatic | Daily |
| Database | Supabase PITR | Continuous (paid plan) |
| Source code | Git | Every commit |
| Configuration | Git | Every commit |

### Recovery

```text
1. Restore database from Supabase backup
2. Re-run: php artisan migrate --force
3. Re-run: php artisan role:seed
4. Re-apply: database/security/grants.sql
5. Verify: health endpoints respond
6. Verify: authentication works
7. Verify: RLS enforced
```

### What IS backed up
- Database schema and data (Supabase)
- Source code (Git)
- Configuration (Git)
- Migrations (Git)

### What is NOT backed up
- Render environment variables (manual backup recommended)
- Supabase project settings
- DNS configuration
- API keys and secrets

---

## Troubleshooting

### Database connection fails
```bash
# Check Supabase status
curl https://status.supabase.com

# Verify connection
psql "postgresql://swasthya_app:PASSWORD@aws-0-ap-south-1.pooler.supabase.com:5432/swasthya?sslmode=require"
```

### Deployment fails at bootstrap
1. Check Render deploy logs
2. Verify BOOTSTRAP_DB_* credentials
3. Ensure postgres user has CREATE ROLE permission
4. Check for migration conflicts

### Application returns 502
1. Check Render service status
2. Verify Docker build logs
3. Check PHP-FPM error logs in Render

### RLS blocking queries
1. Verify swasthya_app role exists and has DML grants
2. Check request.jwt.claims is set correctly
3. Verify RLS policies are current after migration

---

## Deployment Checklist

### First-Time Setup
- [ ] Create Supabase project
- [ ] Create Render blueprint deployment
- [ ] Set environment variables in Render
- [ ] Run initial deployment (bootstrap)
- [ ] Verify health endpoints
- [ ] Seed RBAC catalog
- [ ] Create initial admin user
- [ ] Test authentication flow
- [ ] Verify RLS enforcement

### Ongoing Operations
- [ ] Git push triggers auto-deploy
- [ ] Bootstrap runs on each deploy
- [ ] Migrations apply without errors
- [ ] Health endpoints respond
- [ ] No secrets in logs
- [ ] RLS enforced

### Rollback Procedure
1. Revert to previous Git commit
2. Push to trigger auto-deploy
3. Bootstrap will re-run (safe, idempotent)
4. Verify health endpoints

---

## Related Documentation

- `SUPABASE_STAGING.md` — Detailed Supabase setup
- `backend/database/security/roles.sql` — Role creation
- `backend/database/security/grants.sql` — Grant application
- `backend/docker/entrypoint.sh` — Bootstrap script
- `render.yaml` — Render blueprint configuration
- `SECURITY.md` — Security architecture
- `TENANCY.md` — Multi-tenancy architecture
- `DATABASE.md` — Database schema
- `MASTER_RULES.md` — Engineering rules
