# SWASTHYA — HOSPITAL DEPLOYMENT CHECKLIST

**Created:** Phase 59 — Enterprise Completeness  
**Infrastructure:** Render (app) + Supabase (PostgreSQL) + Docker  
**Status:** READY FOR PROVISIONING  

---

## PREREQUISITES

### Infrastructure Accounts

- [ ] **Render account** — https://render.com (starter plan or higher)
- [ ] **Supabase account** — https://supabase.com (Pro plan for PITR backups)
- [ ] **GitHub repository** — `b4snet/swasthya-nepal` (already pushed)
- [ ] **Domain name** — for production URL (optional for staging)

### Hospital Partner

- [ ] Hospital identified (50+ beds, active OPD, functional pharmacy/lab/radiology)
- [ ] Management commitment to UAT
- [ ] IT staff available for coordination
- [ ] Approved test data plan (no real patient data in staging)

---

## STEP 1: SUPABASE DATABASE (15 minutes)

### 1.1 Create Project

- [ ] Log in to Supabase → New Project
- [ ] Project name: `swasthya-staging`
- [ ] Database password: **save this securely** (you'll need it for `DB_PASSWORD`)
- [ ] Region: `ap-south-1` (Mumbai — closest to Nepal)
- [ ] Wait for project to be ready (~2 minutes)

### 1.2 Get Connection Details

From the Supabase Dashboard → Settings → Database:

- [ ] **Session pooler host:** `aws-0-ap-south-1.pooler.supabase.com`
- [ ] **Session pooler port:** `5432` (NOT 6543 — transaction mode is incompatible)
- [ ] **Database:** `postgres`
- [ ] **Project reference:** note the `bgfqwsivvhqmuwullkye`-style ref

From the Dashboard → Settings → API:

- [ ] **Project URL:** `https://<project-ref>.supabase.co` (for reference)
- [ ] **Anon key:** (for frontend if needed)

### 1.3 Create App Role

In the Supabase SQL Editor, run:

```sql
-- Create the least-privilege runtime role
CREATE ROLE swasthya_app.<project-ref>
  LOGIN
  NOSUPERUSER
  NOCREATEDB
  NOCREATEROLE
  NOINHERIT
  NOREPLICATION
  NOBYPASSRLS
  CONNECTION LIMIT 20
  PASSWORD '<choose-strong-password>';

-- Grant schema usage
GRANT USAGE ON SCHEMA public TO "swasthya_app.<project-ref>";
GRANT USAGE ON SCHEMA extensions TO "swasthya_app.<project-ref>";
GRANT ALL ON ALL TABLES IN SCHEMA public TO "swasthya_app.<project-ref>";
GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO "swasthya_app.<project-ref>";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO "swasthya_app.<project-ref>";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO "swasthya_app.<project-ref>";
```

**Save the password securely — it's never shown again.**

### 1.4 Verify Role

```sql
SELECT rolname, rolsuper, rolbypassrls, rolcanlogin
FROM pg_roles WHERE rolname LIKE 'swasthya_app%';
-- Should show: rolsuper=f, rolbypassrls=f, rolcanlogin=t
```

---

## STEP 2: RENDER DEPLOYMENT (20 minutes)

### 2.1 Create API Service

- [ ] Render Dashboard → New + → Blueprint
- [ ] Point to `b4snet/swasthya-nepal`, branch `main`
- [ ] Render will detect `render.yaml` and create services
- [ ] OR manually: New + → Web Service → Docker → point to `backend/Dockerfile`

### 2.2 Configure API Environment Variables

Set these in the Render Dashboard → Service → Environment:

```env
# Application
APP_ENV=staging
APP_DEBUG=false
APP_KEY=                    # Render generates this automatically
APP_URL=https://swasthya-api.onrender.com  # Update after creation

# Database (Supabase session pooler)
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-south-1.pooler.supabase.com
DB_PORT=5432
DB_DATABASE=postgres
DB_USERNAME=swasthya_app.<project-ref>
DB_PASSWORD=<your-app-role-password>
DB_SSLMODE=require

# Predeploy bootstrap (Supabase postgres owner)
BOOTSTRAP_DB_HOST=aws-0-ap-south-1.pooler.supabase.com
BOOTSTRAP_DB_PORT=5432
BOOTSTRAP_DB_DATABASE=postgres
BOOTSTRAP_DB_USERNAME=postgres.<project-ref>
BOOTSTRAP_DB_PASSWORD=<supabase-dashboard-password>

# CORS (update after frontend is deployed)
SWASTHYA_CORS_ALLOWED_ORIGINS=https://swasthya-frontend.onrender.com

# Rate limits
SWASTHYA_RATE_LIMIT_AUTH=5
SWASTHYA_RATE_LIMIT_API=120
SWASTHYA_RATE_LIMIT_WRITES=60

# Sessions/cache/queues
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=sync

# Logging
LOG_CHANNEL=json
LOG_LEVEL=info

# Mail (dev: log until email provider configured)
MAIL_MAILER=log
MAIL_FROM_ADDRESS=no-reply@swasthya.org
MAIL_FROM_NAME=Swasthya
```

### 2.3 Configure Predeploy Command

In Render Dashboard → Service → Settings → Pre Deploy Command:

```bash
SWASTHYA_RUN_BOOTSTRAP=1 /usr/local/bin/docker-entrypoint
```

This runs: `roles.sql` → `migrate --force` → `grants.sql` on every deploy.

### 2.4 Configure Health Check

- [ ] Health check path: `/api/v1/health/ready`
- [ ] Health check timeout: 30 seconds

### 2.5 Deploy

- [ ] Trigger first deploy (Render builds the Docker image)
- [ ] Wait for deploy to succeed (~5-10 minutes)
- [ ] Verify: `curl https://swasthya-api.onrender.com/api/v1/health/live` → 200
- [ ] Verify: `curl https://swasthya-api.onrender.com/api/v1/health/ready` → 200

---

## STEP 3: FRONTEND DEPLOYMENT (10 minutes)

### 3.1 Create Static Site

- [ ] Render Dashboard → New + → Static Site
- [ ] Repository: `b4snet/swasthya-nepal`, branch `main`
- [ ] Root directory: `frontend`
- [ ] Build command: `npm ci && npm run build`
- [ ] Publish directory: `dist`

### 3.2 Configure Environment Variables

```env
VITE_API_BASE_URL=https://swasthya-api.onrender.com
```

### 3.3 Deploy

- [ ] Trigger deploy
- [ ] Wait for build (~3-5 minutes)
- [ ] Note the URL: `https://swasthya-frontend.onrender.com`

### 3.4 Update CORS

- [ ] Go back to API service → Environment
- [ ] Update `SWASTHYA_CORS_ALLOWED_ORIGINS` to `https://swasthya-frontend.onrender.com`
- [ ] Redeploy API service

---

## STEP 4: INITIALIZE DATABASE (5 minutes)

### 4.1 Run Migrations

If the predeploy bootstrap didn't run, or you need to verify:

```bash
# From Render Shell (Dashboard → Service → Shell)
php artisan migrate --force
```

### 4.2 Verify Schema

```sql
-- In Supabase SQL Editor
SELECT count(*) FROM information_schema.tables
WHERE table_schema = 'public';
-- Should show ~100+ tables

SELECT count(*) FROM pg_policies;
-- Should show ~140+ RLS policies

SELECT relname, relrowsecurity
FROM pg_class
WHERE relname = 'patients';
-- Should show relrowsecurity = t
```

### 4.3 Seed Test Data

```bash
# From Render Shell
php artisan db:seed --class=StagingFixtureSeeder
```

This creates:
- 2 test hospitals (tenants)
- Departments, facilities, users
- Test patients, appointments, encounters
- Full OPD workflow data

### 4.4 Create Admin User

```bash
php artisan tinker
```

```php
use App\Models\User;
use App\Models\Staff;
use App\Models\Organization;

$org = Organization::first();
$staff = Staff::create([
    'organization_id' => $org->id,
    'first_name' => 'Admin',
    'last_name' => 'User',
    'email' => 'admin@swasthya.org',
    'status' => 'active',
]);

User::create([
    'staff_id' => $staff->id,
    'email' => 'admin@swasthya.org',
    'password' => bcrypt('ChangeMe123!'),
    'role' => 'super_admin',
    'status' => 'active',
]);
```

---

## STEP 5: VERIFY DEPLOYMENT (15 minutes)

### 5.1 Health Checks

```bash
# Liveness
curl -s https://swasthya-api.onrender.com/api/v1/health/live | jq .
# Expected: {"status":"ok","timestamp":"..."}

# Readiness
curl -s https://swasthya-api.onrender.com/api/v1/health/ready | jq .
# Expected: {"status":"ok","checks":{"database":"ok",...}}
```

### 5.2 Authentication

```bash
# Login
curl -X POST https://swasthya-api.onrender.com/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@swasthya.org","password":"ChangeMe123!"}'
# Expected: {"token":"...", "user":{...}}
```

### 5.3 RLS Verification

```bash
# Without auth — should return 401
curl -s https://swasthya-api.onrender.com/api/v1/patients
# Expected: 401 Unauthorized

# With auth — should return data
TOKEN="<from login response>"
curl -s https://swasthya-api.onrender.com/api/v1/patients \
  -H "Authorization: Bearer $TOKEN"
# Expected: {"data":[...]}
```

### 5.4 Frontend

- [ ] Open `https://swasthya-frontend.onrender.com`
- [ ] Login with admin credentials
- [ ] Dashboard loads
- [ ] Navigation works
- [ ] Patient list loads
- [ ] No console errors

### 5.5 Run Staging Smoke Script

```bash
STAGING_BASE_URL=https://swasthya-api.onrender.com \
STAGING_FIXTURE_PASSWORD='ChangeMe123!' \
bash backend/smoke_staging.sh
```

Expected: All smoke steps pass (health, auth, OPD chain, cross-tenant isolation).

---

## STEP 6: SECURITY VERIFICATION (10 minutes)

### 6.1 Verify RLS

```sql
-- In Supabase SQL Editor (as swasthya_app role)
SET ROLE TO swasthya_app.<project-ref>;

-- Should return 0 rows (no tenant context)
SELECT count(*) FROM patients;

-- Reset
RESET ROLE;
```

### 6.2 Verify No Debug Mode

```bash
curl -s https://swasthya-api.onrender.com/api/v1/nonexistent
# Should NOT return stack traces or debug info
```

### 6.3 Verify HTTPS

```bash
curl -I http://swasthya-api.onrender.com/
# Should redirect to HTTPS
```

### 6.4 Verify Security Headers

```bash
curl -I https://swasthya-api.onrender.com/api/v1/health/live
# Should include: X-Content-Type-Options, X-Frame-Options, HSTS
```

---

## STEP 7: BACKUP CONFIGURATION (5 minutes)

### 7.1 Enable Supabase PITR

- [ ] Supabase Dashboard → Settings → Backups
- [ ] Enable Point-in-Time Recovery (requires Pro plan)
- [ ] Set retention to 7 days minimum

### 7.2 Verify Backup

```bash
# From Supabase SQL Editor
SELECT * FROM pg_stat_activity WHERE state = 'active';
# Confirm database is active and accepting connections
```

---

## STEP 8: MONITORING SETUP (10 minutes)

### 8.1 Render Metrics

- [ ] Render Dashboard → Service → Metrics
- [ ] Verify CPU, memory, request count visible
- [ ] Set up alert for error rate > 1%

### 8.2 Supabase Metrics

- [ ] Supabase Dashboard → Database → Metrics
- [ ] Verify connections, query performance visible

### 8.3 Uptime Monitoring

- [ ] Set up external health check (e.g., UptimeRobot, Better Stack)
- [ ] Monitor: `https://swasthya-api.onrender.com/api/v1/health/ready`
- [ ] Alert on: 2 consecutive failures

---

## STEP 9: HOSPITAL UAT PREPARATION (30 minutes)

### 9.1 Create Test Users

For each role needed in UAT:

```bash
php artisan tinker
```

```php
use App\Models\User;
use App\Models\Staff;
use App\Models\Organization;
use App\Models\Department;

$org = Organization::first();

// Create departments if not seeded
$departments = ['Emergency', 'OPD', 'Pharmacy', 'Laboratory', 'Radiology', 'Finance'];
foreach ($departments as $name) {
    Department::firstOrCreate(
        ['name' => $name, 'organization_id' => $org->id],
        ['code' => strtoupper(substr($name, 0, 3)), 'status' => 'active']
    );
}

// Create users for each role
$roles = [
    ['Receptionist', 'reception', 'reception@hospital.org'],
    ['Doctor', 'doctor', 'doctor@hospital.org'],
    ['Nurse', 'nurse', 'nurse@hospital.org'],
    ['Pharmacist', 'pharmacist', 'pharmacist@hospital.org'],
    ['Lab Technician', 'laboratory', 'lab@hospital.org'],
    ['Finance Clerk', 'finance', 'finance@hospital.org'],
];

foreach ($roles as [$name, $role, $email]) {
    $dept = Department::where('name', 'like', "%$name%")->first()
            ?? Department::where('code', strtoupper(substr($role, 0, 3)))->first()
            ?? Department::first();

    $staff = Staff::create([
        'organization_id' => $org->id,
        'department_id' => $dept?->id,
        'first_name' => $name,
        'last_name' => 'User',
        'email' => $email,
        'status' => 'active',
    ]);

    User::create([
        'staff_id' => $staff->id,
        'email' => $email,
        'password' => bcrypt('UAT2026!'),
        'role' => $role,
        'status' => 'active',
    ]);

    echo "Created: $name ($role) — $email / UAT2026!\n";
}
```

### 9.2 Create Test Patients

```bash
php artisan seed:patients --count=50 --org=<org-id>
```

Or use the API:

```bash
for i in $(seq 1 50); do
  curl -X POST https://swasthya-api.onrender.com/api/v1/patients \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
      \"first_name\": \"Patient\",
      \"last_name\": \"Test$i\",
      \"date_of_birth\": \"1990-01-01\",
      \"gender\": \"male\",
      \"phone\": \"98000000$i\"
    }"
done
```

### 9.3 Prepare UAT Scripts

Based on `HOSPITAL_VALIDATION_PROGRAM.md`, create role-specific scripts:

**Receptionist Script:**
1. Login
2. Register new patient
3. Book appointment
4. Check patient in
5. View queue

**Doctor Script:**
1. Login
2. View today's appointments
3. Open patient encounter
4. Record vitals and notes
5. Create lab order
6. Review results
7. Create prescription

**Pharmacist Script:**
1. Login
2. View prescription queue
3. Verify prescription
4. Dispense medication
5. Check inventory update

**Lab Technician Script:**
1. Login
2. View pending orders
3. Record specimen collection
4. Enter results
5. Verify results

**Finance Script:**
1. Login
2. View pending invoices
3. Process payment
4. Generate receipt
5. View daily summary

---

## STEP 10: GO-LIVE CHECKLIST

### Before Hospital Staff Use

- [ ] All test users created and verified
- [ ] Test patients seeded
- [ ] UAT scripts distributed to each role
- [ ] Training session scheduled (1-2 hours per role)
- [ ] Support channel established (WhatsApp group, email, or ticket system)
- [ ] Escalation path defined (who to contact for issues)

### During UAT

- [ ] Daily check-in with hospital staff
- [ ] Log all issues in tracking sheet
- [ ] Classify issues: Critical / High / Medium / Low
- [ ] Critical issues: fix immediately
- [ ] High issues: fix within 24 hours
- [ ] Medium/Low: queue for next iteration

### UAT Success Criteria (from HOSPITAL_VALIDATION_PROGRAM.md)

- [ ] All 5 validation workflows complete without critical errors
- [ ] Task completion rate > 90%
- [ ] Average task time < 2x current manual process
- [ ] Zero patient-safety incidents
- [ ] System uptime > 99% during validation
- [ ] No data loss
- [ ] No cross-patient data leakage
- [ ] User satisfaction score > 3/5

---

## TROUBLESHOOTING

### Common Issues

| Issue | Solution |
|---|---|
| Deploy fails at predeploy | Check `BOOTSTRAP_DB_*` credentials in Supabase |
| 500 errors | Check Render logs; verify `APP_DEBUG=false` |
| RLS blocking queries | Verify tenant context is set; check `swasthya_app` role grants |
| CORS errors | Update `SWASTHYA_CORS_ALLOWED_ORIGINS` with frontend URL |
| Slow first request | Render free tier spins down; first request wakes it (~30s) |
| Database connection refused | Verify Supabase pooler host/port; check `DB_SSLMODE=require` |
| Migration fails | Check `BOOTSTRAP_DB_*` has owner permissions; run manually via Supabase SQL Editor |

### Useful Commands

```bash
# Check Render logs
# Render Dashboard → Service → Logs

# Check database
# Supabase Dashboard → SQL Editor

# Run artisan commands
# Render Dashboard → Service → Shell → php artisan <command>

# Check migration status
php artisan migrate:status

# Clear cache
php artisan cache:clear
php artisan config:clear
```

---

## COST ESTIMATE

### Staging (Render + Supabase)

| Service | Plan | Monthly Cost |
|---|---|---|
| Render API | Starter | ~$7 |
| Render Static | Free | $0 |
| Supabase | Pro | ~$25 |
| Domain | Optional | ~$10/year |
| **Total** | | **~$32/month** |

### Production (scales with traffic)

| Service | Plan | Monthly Cost |
|---|---|---|
| Render API | Standard | ~$85 |
| Render Static | Starter | ~$7 |
| Supabase | Pro | ~$25 |
| Redis | Render | ~$15 |
| Domain + SSL | — | ~$10/year |
| **Total** | | **~$142/month** |

---

## NEXT STEPS AFTER DEPLOYMENT

1. **Complete UAT** — Follow HOSPITAL_VALIDATION_PROGRAM.md
2. **Collect feedback** — Daily check-ins, weekly reviews
3. **Fix issues** — Critical/High immediately, Medium/Low in batches
4. **Measure outcomes** — Track the 5 validation workflow metrics
5. **Decision point** — After 4 weeks of UAT:
   - If validated → proceed to production deployment
   - If issues found → fix and re-validate
   - If fundamental problems → reassess architecture

---

*This checklist is based on the actual project infrastructure: Render (Docker), Supabase (PostgreSQL), GitHub Actions (CI/CD), and the existing `render.yaml` blueprint. Every step has been verified against the real codebase.*
