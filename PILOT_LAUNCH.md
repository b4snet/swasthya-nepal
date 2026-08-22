# PILOT_LAUNCH.md — SWASTHYA Controlled Hospital Pilot

> **Status:** Launch-ready documentation
> **Release:** `ec6f3d2` on `main`
> **Date:** August 22, 2026
> **Authorization Required:** YES — explicit human authorization before any deployment

---

## 0. CRITICAL RULES

1. **Do NOT deploy automatically.** Every deployment step requires explicit human authorization.
2. **Do NOT onboard real PHI without formal hospital authorization.** Synthetic data first.
3. **Do NOT reuse staging as production.** Dedicated pilot environment required.
4. **Do NOT introduce unrelated features during pilot.** Only blocker/critical/security fixes.
5. **Do NOT fabricate pilot acceptance.** Only record acceptance supported by actual stakeholder evidence.

---

## 1. Pilot Environment Requirements

### 1.1 Infrastructure

| Component | Requirement | Status |
|---|---|---|
| Domain | Dedicated subdomain (e.g., pilot.swasthya.health) | ⬜ Pending |
| HTTPS | TLS 1.2+ certificate (Let's Encrypt or commercial) | ⬜ Pending |
| DNS | A/CNAME records pointing to pilot server | ⬜ Pending |
| Application Server | PHP 8.2+ with Laravel, Node.js 18+ | ⬜ Pending |
| Database | PostgreSQL 15+ with RLS enabled | ⬜ Pending |
| Cache/Queue | Redis 7+ (production) or database-backed | ⬜ Pending |
| Storage | S3-compatible object storage or local | ⬜ Pending |
| Realtime | WebSocket server (Laravel Reverb or Soketi) | ⬜ Pending |
| CDN | Optional — for static assets | ⬜ Pending |
| Backup | Daily base backups + WAL archiving | ⬜ Pending |
| Monitoring | Application + infrastructure metrics | ⬜ Pending |
| Logging | Centralized structured JSON logs | ⬜ Pending |
| Alerting | PagerDuty/Slack integration | ⬜ Pending |

### 1.2 Environment Variables

```env
# Application
APP_NAME="Swasthya Pilot"
APP_ENV=production
APP_KEY=<generated-32-byte-key>
APP_DEBUG=false
APP_URL=https://pilot.swasthya.health

# Database
DB_CONNECTION=pgsql
DB_HOST=<database-host>
DB_PORT=5432
DB_DATABASE=swasthya_pilot
DB_USERNAME=swasthya_app
DB_PASSWORD=<strong-random-password>

# Cache / Queue
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_HOST=<redis-host>
REDIS_PASSWORD=<redis-password>

# Authentication
SANCTUM_STATEFUL_DOMAINS=pilot.swasthya.health

# CORS
SWASTHYA_CORS_ALLOWED_ORIGINS=https://pilot.swasthya.health

# Rate Limits
SWASTHYA_RATE_LIMIT_AUTH=5
SWASTHYA_RATE_LIMIT_API=60

# Storage
FILESYSTEM_DISK=s3
AWS_BUCKET=<pilot-bucket>
AWS_ACCESS_KEY_ID=<key>
AWS_SECRET_ACCESS_KEY=<secret>

# Mail (where authorized)
MAIL_MAILER=smtp
MAIL_HOST=<smtp-host>
MAIL_PORT=587
MAIL_USERNAME=<username>
MAIL_PASSWORD=<password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@pilot.swasthya.health

# Backup
BACKUP_DISK=s3
BACKUP_BUCKET=<backup-bucket>
```

---

## 2. Security Configuration Checklist

| # | Item | Status |
|---|---|---|
| 1 | APP_DEBUG=false | ⬜ |
| 2 | APP_KEY generated (32 bytes) | ⬜ |
| 3 | HTTPS enforced (HSTS) | ⬜ |
| 4 | CORS allowlist set to pilot domain only | ⬜ |
| 5 | CSP headers configured | ⬜ |
| 6 | Cookie secure=true, httponly=true, samesite=lax | ⬜ |
| 7 | Rate limits configured (auth: 5/min, API: 60/min) | ⬜ |
| 8 | MFA enabled for admin accounts | ⬜ |
| 9 | Session timeout configured | ⬜ |
| 10 | RLS enabled on all PHI tables | ⬜ |
| 11 | FORCE RLS on sensitive tables | ⬜ |
| 12 | Audit trail enabled | ⬜ |
| 13 | Secrets in production vault (not .env files) | ⬜ |
| 14 | No source maps in build output | ⬜ |
| 15 | No debug endpoints exposed | ⬜ |

---

## 3. Hospital Configuration

### 3.1 Organization Setup

| # | Item | Value |
|---|---|---|
| 1 | Organization name | [Pilot Hospital Name] |
| 2 | Organization code | [Unique code] |
| 3 | Timezone | Asia/Kathmandu |
| 4 | Currency | NPR |
| 5 | Locale | en (with ne support) |

### 3.2 Facility Setup

| # | Item | Value |
|---|---|---|
| 1 | Facility name | [Main facility] |
| 2 | Facility code | [Unique code] |
| 3 | Address | [Hospital address] |
| 4 | Phone | [Contact number] |

### 3.3 Departments (Representative)

| Department | Type | Code |
|---|---|---|
| Outpatient (OPD) | Clinical | OPD |
| Inpatient (IPD) | Clinical | IPD |
| Emergency | Clinical | ER |
| ICU | Clinical | ICU |
| Operating Theatre | Clinical | OT |
| Pharmacy | Pharmacy | PHARM |
| Laboratory | Laboratory | LAB |
| Radiology | Radiology | RAD |
| Blood Bank | Clinical | BB |
| Oncology | Clinical | ONCO |
| Billing | Administrative | BILL |
| HR | Administrative | HR |
| Store/Inventory | Administrative | STORE |

### 3.4 Roles (Pilot Scope)

| Role | Access Level |
|---|---|
| Hospital Admin | Full organization management |
| Doctor | Clinical workspace, encounters, orders |
| Nurse | Ward tasks, vitals, care plans |
| Receptionist | Appointments, queue, registration |
| Pharmacist | Prescriptions, dispensing, inventory |
| Lab Technician | Lab worklist, specimen, results |
| Radiologist | Imaging interpretation, reports |
| Billing Staff | Charges, invoices, payments |
| Inventory Staff | Stock, receiving, transfers |
| Patient | Portal only |

---

## 4. Module Entitlement

Enable ONLY modules purchased/approved by the pilot hospital.

| Module | Enabled | Notes |
|---|---|---|
| Patient Records | ⬜ | Core — always enabled |
| Appointments | ⬜ | Core — always enabled |
| Emergency | ⬜ | If ED exists |
| IPD | ⬜ | If inpatient services |
| ICU | ⬜ | If critical care |
| OT | ⬜ | If surgical services |
| Pharmacy | ⬜ | If pharmacy services |
| Laboratory | ⬜ | If lab services |
| Radiology | ⬜ | If imaging services |
| Blood Bank | ⬜ | If blood bank |
| Oncology | ⬜ | If cancer services |
| Billing | ⬜ | Core — always enabled |
| Procurement | ⬜ | If supply chain |
| HR | ⬜ | If workforce mgmt |
| Patient Portal | ⬜ | If patient self-service |
| Telemedicine | ⬜ | If video consultations |
| Analytics | ⬜ | If reporting needs |
| Research | ⬜ | If academic hospital |

---

## 5. Synthetic Smoke Test Checklist

Before real operations, run with synthetic data:

| # | Workflow | Expected Result |
|---|---|---|
| 1 | Patient registration | MRN generated, record created |
| 2 | Appointment booking | Slot reserved, queue entry |
| 3 | Encounter start | Clinical workspace active |
| 4 | Diagnosis entry | ICD-coded, saved |
| 5 | Prescription | Medication order created |
| 6 | Lab order | Specimen tracked |
| 7 | Radiology order | Study created |
| 8 | Pharmacy dispense | Inventory deducted |
| 9 | Billing invoice | Invoice generated |
| 10 | Payment | Receipt created |
| 11 | IPD admission | Bed assigned |
| 12 | Discharge | Summary generated |
| 13 | Patient portal login | Own data visible |
| 14 | Staff login (doctor) | Clinical scope only |
| 15 | Staff login (nursing) | Ward scope only |
| 16 | Cross-tenant isolation | Zero data leakage |

---

## 6. Pilot Incident Process

| Severity | Response Time | Escalation |
|---|---|---|
| P1 — Critical (platform down) | < 15 min | Immediate — all hands |
| P2 — High (major feature broken) | < 1 hour | Engineering lead |
| P3 — Medium (non-critical issue) | < 4 hours | Support team |
| P4 — Low (minor/cosmetic) | < 24 hours | Normal queue |

---

## 7. Rollback Procedure

1. **Stop traffic** to pilot environment
2. **Restore database** from most recent backup
3. **Redeploy** previous known-good application version
4. **Verify** health endpoints return OK
5. **Notify** hospital IT team
6. **Investigate** root cause before re-deploy

---

## 8. Go/No-Go Criteria

### GO Criteria (all must be true)
- [ ] Hospital has formally authorized pilot deployment
- [ ] Pilot environment provisioned and verified
- [ ] Security configuration complete
- [ ] Hospital configuration complete
- [ ] Synthetic smoke test passed
- [ ] Monitoring and alerting active
- [ ] Rollback procedure tested
- [ ] Hospital IT team trained
- [ ] On-call support established

### NO-GO Criteria (any blocks deployment)
- [ ] No formal hospital authorization
- [ ] Critical security vulnerability unresolved
- [ ] Database backup not verified
- [ ] Monitoring not active
- [ ] Rollback procedure not tested
- [ ] Hospital IT team not trained

---

*This document must be reviewed and approved by the hospital IT director and project lead before any deployment.*
