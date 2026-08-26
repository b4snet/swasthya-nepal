# Swasthya — Disaster Recovery and Business Continuity

> **Version:** 1.0 | **Phase:** 92 Disaster Recovery
> **Status:** Resilience model defined, chaos matrix established, recovery runbooks documented

---

## Resilience Model

```text
FAILURE
↓
DETECT
↓
PROTECT PATIENT CARE
↓
CONTAIN
↓
RESTORE
↓
RECONCILE
↓
VERIFY
↓
RESUME
↓
LEARN
```

---

## RTO/RPO Targets

| Component | RTO Target | RPO Target | Current Evidence |
|-----------|-----------|-----------|-----------------|
| Database | 30 min | 0 (WAL) | Supabase PITR 7-day |
| Application | 5 min | 0 | Docker rebuild + restart |
| Cache (Redis) | 5 min | 0 (rebuild) | Not critical path |
| Queue | 10 min | 0 (persistent) | Database queue |
| Storage | 15 min | 0 | Supabase backup |
| Authentication | 5 min | 0 | Database-backed sessions |

---

## Criticality Matrix

| System | Priority | Impact if Down | Recovery Priority |
|--------|----------|---------------|-------------------|
| Patient Identity | CRITICAL | Cannot identify patients | 1 |
| Clinical Records | CRITICAL | Cannot treat patients | 1 |
| Emergency | CRITICAL | Patient safety risk | 1 |
| Database | CRITICAL | All systems down | 1 |
| Authentication | HIGH | Cannot access system | 2 |
| Orders/Results | HIGH | Cannot order/review | 2 |
| Medication | HIGH | Cannot prescribe/dispense | 2 |
| Billing | MEDIUM | Revenue delayed | 3 |
| Documents | MEDIUM | Access delayed | 3 |
| Communication | MEDIUM | Notifications delayed | 4 |
| Analytics | LOW | Reports delayed | 5 |
| AI | LOW | Optional features | 5 |

---

## Dependency Map

```text
FRONTEND (React SPA)
  ↓
API (PHP-FPM + nginx)
  ├── PostgreSQL (Primary database)
  │   └── RLS policies
  │   └── Helper functions
  │   └── Application role
  ├── Redis (Cache/Session/Queue) [optional]
  ├── Queue Workers [database queue]
  ├── Storage (Object/File)
  └── External Providers
      ├── Payment (Sandbox)
      ├── SMS/Email
      ├── LIS
      └── PACS/RIS
```

### Single Points of Failure

| Component | SPOF? | Impact | Mitigation |
|-----------|-------|--------|-----------|
| PostgreSQL | Yes | Total outage | Supabase PITR, daily backup |
| Application server | Yes | API down | Quick Docker rebuild |
| Redis | No | Degraded (cache miss) | Database fallback |
| Object storage | No | File access delayed | Manual fallback |
| Payment provider | Yes | Payment delay | Queue retry, reconciliation |
| AI provider | No | AI features degraded | Core HMS unaffected |

---

## Failure Scenarios and Recovery

### Database Unavailable

**Detection:**
- Health endpoint returns unhealthy
- API returns 503
- Application logs connection errors

**Immediate Action:**
1. Check Supabase status
2. Check network connectivity
3. Check connection pool

**Recovery:**
1. Wait for Supabase recovery (if provider outage)
2. Or restore from PITR backup
3. Run `php artisan migrate --force` (safe, idempotent)
4. Verify health endpoint

**Post-Recovery:**
1. Verify RLS integrity
2. Check data consistency
3. Review audit log
4. Resume operations

**RTO:** 30 min | **RPO:** 0 (WAL replay)

---

### Database Loss (Catastrophic)

**Detection:**
- All queries fail
- Health endpoint returns unhealthy
- No connections possible

**Immediate Action:**
1. Declare database outage
2. Notify hospital operations
3. Switch to downtime procedures (paper/manual)

**Recovery:**
1. Restore from Supabase PITR (point-in-time recovery)
2. Or restore from daily backup
3. Run roles.sql (recreate swasthya_app role)
4. Run migrate --force (safe, idempotent)
5. Run grants.sql (reapply DML grants)
6. Verify health endpoint
7. Verify RLS integrity
8. Reconcile data

**Post-Recovery:**
1. Full RLS verification (postRestoreRLSVerification)
2. Data integrity check
3. Audit log review
4. Resume operations
5. Incident report

**RTO:** 30-60 min | **RPO:** 0-15 min (PITR granularity)

---

### Redis Unavailable

**Detection:**
- Health endpoint returns degraded
- Cache misses increase
- Session issues possible

**Immediate Action:**
- Redis is optional — core HMS continues
- Monitor degradation

**Recovery:**
1. Restart Redis service
2. Reconnection automatic
3. Cache rebuilds on demand

**RTO:** 5 min | **RPO:** 0 (cache rebuild)

---

### Queue Worker Failure

**Detection:**
- Pending jobs increase
- Notifications delayed
- Background tasks stalled

**Immediate Action:**
- Check worker process status
- Check job queue depth

**Recovery:**
1. Restart worker process
2. Jobs automatically retry
3. Queue backlog decreases

**RTO:** 5 min | **RPO:** 0 (jobs persistent in DB)

---

### All Workers Down

**Detection:**
- Queue depth grows
- No notifications sent
- Background tasks stalled

**Immediate Action:**
- Confirm all workers stopped
- Check database queue table

**Recovery:**
1. Restart all workers
2. Jobs process in order
3. No duplicate mutations (idempotency keys)

**RTO:** 5 min | **RPO:** 0

---

### Application Server Failure

**Detection:**
- Load balancer health check fails
- HTTP 502/503 errors
- Frontend cannot reach API

**Immediate Action:**
- Check Docker container status
- Check PHP-FPM/nginx

**Recovery:**
1. Docker container auto-restarts (restart: unless-stopped)
2. Or rebuild: `docker compose up -d --build`
3. Health endpoint returns OK

**RTO:** 5 min | **RPO:** 0

---

### Storage Unavailable

**Detection:**
- File upload failures
- Document retrieval failures
- Image loading failures

**Immediate Action:**
- Check storage connectivity
- Existing records remain valid

**Recovery:**
1. Restore storage connectivity
2. New uploads work
3. Old documents accessible

**RTO:** 15 min | **RPO:** 0 (stored files)

---

### External Payment Provider Failure

**Detection:**
- Payment processing fails
- Callbacks timeout
- Reconciliation shows pending

**Immediate Action:**
- Invoice remains authoritative
- Payment status shows "pending"

**Recovery:**
1. Queue retry for failed payments
2. Provider recovery = automatic
3. Manual reconciliation if needed

**RTO:** Provider-dependent | **RPO:** 0

---

### Authentication Failure

**Detection:**
- Login fails
- Token validation fails
- SSO unavailable

**Immediate Action:**
- Check auth provider status
- Check token signing keys

**Recovery:**
1. Auth provider recovery
2. Or use fallback auth (database-backed)
3. Sessions restored

**RTO:** 5 min | **RPO:** 0

---

## Downtime Procedures

### During Database Outage

1. **Switch to paper forms** (pre-printed)
2. **Record manually:**
   - Patient name, MRN (if known)
   - Time of encounter
   - Clinical notes
   - Medications ordered
   - Labs ordered
3. **Do NOT create duplicate patients**
4. **Queue for later reconciliation**

### During Application Outage

1. **Use backup systems** (paper, approved manual forms)
2. **Record operations manually**
3. **Queue for later entry**
4. **Do NOT attempt manual database edits**

### Downtime Reconciliation

```text
MANUAL RECORDS
  ↓
CAPTURE (data entry)
  ↓
VALIDATE (against rules)
  ↓
RECONCILE (against existing data)
  ↓
AUDIT (log all entries)
  ↓
NORMAL OPERATIONS
```

---

## Business Continuity Runbook

### Outage Declaration

1. Health endpoint returns unhealthy/degraded
2. Operator confirms outage
3. Notify hospital operations
4. Switch to downtime procedures

### Recovery Sequence

```text
1. DATABASE
   ├── Verify connectivity
   ├── Run migrate --force
   ├── Verify RLS
   └── Verify data integrity

2. APPLICATION
   ├── Verify Docker container
   ├── Verify health endpoints
   └── Verify API responses

3. QUEUE
   ├── Verify worker status
   ├── Check queue depth
   └── Resume processing

4. STORAGE
   ├── Verify file access
   └── Test upload/download

5. INTEGRATIONS
   ├── Verify payment provider
   ├── Verify SMS/email
   └── Verify external systems

6. VALIDATION
   ├── Health check: all green
   ├── RLS check: all pass
   ├── Data integrity: all accessible
   ├── Audit log: no gaps
   └── Resume normal operations
```

### Incident Communication

| Audience | Channel | Message |
|----------|---------|---------|
| Hospital operations | Direct contact | "System temporarily unavailable. Using downtime procedures." |
| Internal team | Slack/incident channel | Technical details and ETA |
| Support | Ticket system | Status updates |
| Patients | Portal message | "Service temporarily unavailable" |

---

## Chaos Matrix

| Scenario | Detection | Recovery | Data Loss | RTO | RPO |
|----------|-----------|----------|-----------|-----|-----|
| Database down | Health check | Supabase PITR | None | 30min | 0 |
| Redis down | Health check | Restart/reconnect | None | 5min | 0 |
| Worker down | Queue depth | Restart worker | None | 5min | 0 |
| App down | LB health | Docker restart | None | 5min | 0 |
| Storage down | Upload fail | Restore connectivity | None | 15min | 0 |
| Payment fail | Reconciliation | Queue retry | None | Provider | 0 |
| Auth fail | Login fail | Fallback auth | None | 5min | 0 |
| Network down | Timeouts | Network restore | None | Variable | 0 |

---

## Recovery Priority Order

```text
1. DATABASE (everything depends on it)
2. AUTHENTICATION (must access before using)
3. CLINICAL RECORDS (patient safety)
4. EMERGENCY (patient safety)
5. ORDERS/RESULTS (clinical workflow)
6. MEDICATION (patient safety)
7. BILLING (revenue)
8. DOCUMENTS (access)
9. COMMUNICATION (notifications)
10. ANALYTICS (reports)
```

---

## Security During Recovery

**Non-negotiable:**
- RLS must remain active
- Application role must maintain NOBYPASSRLS
- Secrets must not be exposed
- Audit must continue
- Tenant isolation must hold

**Never:**
- Disable RLS for recovery convenience
- Use production credentials in recovery scripts
- Skip RLS verification after restore
- Restore into a system with security disabled

---

## AI Degradation

**AI is OPTIONAL.** Core HMS continues when AI is unavailable.

```text
AI AVAILABLE → AI FAILS → CORE HMS CONTINUES
```

No core workflow depends on AI availability:
- Patient registration: ✅ Works without AI
- Clinical documentation: ✅ Works without AI
- Orders/Results: ✅ Works without AI
- Billing: ✅ Works without AI
- Pharmacy: ✅ Works without AI

---

## Backup and Recovery

### Backup Strategy

| Component | Method | Frequency | Retention |
|-----------|--------|-----------|-----------|
| Database | Supabase PITR | Continuous | 7 days (paid) |
| Database | Supabase daily | Daily | 30 days |
| Source code | Git | Every commit | Forever |
| Configuration | Git | Every commit | Forever |
| Storage | Supabase backup | Daily | 30 days |

### Restore Procedure

```text
1. RESTORE DATABASE
   psql -f backup.sql (or Supabase PITR)

2. CREATE ROLES
   psql -f database/security/roles.sql

3. RUN MIGRATIONS
   php artisan migrate --force

4. REAPPLY GRANTS
   psql -f database/security/grants.sql

5. VERIFY HEALTH
   curl /api/v1/health/ready

6. VERIFY RLS
   php artisan test --filter=ClaimsBasedRlsTest

7. VERIFY DATA
   Check critical tables
```

---

## Tenant Isolation Under Failure

### Hospital A Failure

```
Hospital A: DATABASE ISSUE
Hospital B: UNAFFECTED
Hospital C: UNAFFECTED
```

RLS ensures tenant isolation at the database level. A failure in one hospital's data does not affect others.

### Blast Radius

| Failure | Blast Radius | Isolation Mechanism |
|---------|-------------|-------------------|
| Hospital A data issue | Hospital A only | RLS tenant_id |
| Hospital A config issue | Hospital A only | RLS tenant_id |
| Application crash | All hospitals | Quick restart |
| Database crash | All hospitals | Restore from backup |
| Redis failure | All (degraded) | Database fallback |

---

## Monitoring During Recovery

Operators must see:
- Component status (database, app, queue, storage)
- Pending/failed jobs
- Health check results
- RLS status
- Data integrity status
- Audit log continuity

---

## Recovery Test Frequency

| Test | Frequency | Environment |
|------|-----------|-------------|
| Backup restore | Monthly | Staging/disposable |
| RLS verification | After every restore | Any |
| Chaos scenario | Quarterly | Staging/disposable |
| Full DR rehearsal | Annually | Staging/disposable |

---

## Related Documentation

- `STAGING.md` — Staging operations
- `SECURITY.md` — Security architecture
- `TENANCY.md` — Multi-tenancy
- `DATA_GOVERNANCE.md` — Data governance
- `backend/app/Services/ResilienceService.php` — Health verification
- `backend/app/Http/Controllers/Api/HealthController.php` — Health endpoints
- `backend/database/security/roles.sql` — Role creation
- `backend/database/security/grants.sql` — Grant application
