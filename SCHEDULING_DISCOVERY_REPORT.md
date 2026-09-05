# SWASTHYA — APPOINTMENTS / SCHEDULING STOP REPORT

**Phase:** Discovery only (no code changes)
**Branch:** `main` (HEAD `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6`)
**Date:** 2026-09-04
**Disposition:** STOP — discovery complete, no modifications made. Report below.

---

## 1. Baseline

- **branch:** `main`
- **HEAD:** `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6`
- **remote/main:** `9fc3508eb6e848fc92a43d94694819a6c462f1cd`
- **working tree:** prior-phase uncommitted changes (Insurance/Coverage hardening + Patient Contact/Identifiers) remain untouched
- **scheduling tests:** `AppointmentBookingTest` 11 passed (74 assertions); `DoctorScheduleTest` 1 failed (pre-existing `Staff::casts()` undefined method — out of scope)

## 2. Existing Scheduling Capability (verified)

### 2.1 Core tables

| Table | Purpose | RLS |
|---|---|---|
| `schedule_templates` | Recurring weekly provider availability (staff + service + day_of_week + time window + slot_minutes + capacity + validity window). Soft-deletable. | TENANT_FACILITY |
| `schedule_exceptions` | Leave / holiday / block per provider per date (one per staff+date). NOT soft-deleted (date-scoped expiry). | TENANT_FACILITY |
| `appointments` | Patient × provider × slot booking. Never soft-deleted (cancelled = history). | TENANT_FACILITY |
| `token_counters` | Row-locked queue token sequence per (tenant, facility, provider, date). `SELECT ... FOR UPDATE`. | TENANT_FACILITY |
| `services` | Facility clinical offerings (OPD consultation, procedure, investigation). `default_duration_minutes` exists but NOT wired to template slot generation. | TENANT_FACILITY |
| `rooms` | Rooms within wards (room_type, daily_rate). | TENANT_FACILITY_BRANCH |
| `resource_bookings` | Generic resource time-block bookings (OT/imaging/equipment/room/bed). App-level overlap check. | TENANT_FACILITY |
| `queue_entries` | Department-level patient queue (priority, token, status). | TENANT_FACILITY |

### 2.2 Derived availability engine

`SlotService::slotsFor()` — **availability is derived, never stored**:

1. Get day-of-week from requested date
2. Query `ScheduleTemplates` for (tenant, staff, day_of_week, active, validity window)
3. Check for any active `ScheduleException` → if exists, return **empty** (entire day blocked)
4. Count existing live bookings per start-time (`booked`, `checked_in`, `in_consultation`)
5. Generate slots from templates; each slot: `available = booked < capacity`

### 2.3 Double-booking prevention (two-layer)

**Layer 1 — Application:** `AppointmentController::store()` calls `SlotService::slotsFor()` and validates the requested slot appears with `available: true`.

**Layer 2 — Database:** Partial unique index:
```sql
CREATE UNIQUE INDEX uq_appointments_tenant_provider_start
ON appointments (tenant_id, provider_staff_id, starts_at)
WHERE status IN ('booked', 'checked_in', 'in_consultation')
```
Parallel requests race on this index; first INSERT wins, second catches `QueryException` → 409 CONFLICT.

### 2.4 Appointment status lifecycle

```
booked → checked_in → in_consultation → completed
booked → cancelled (reason required)
booked → no_show
checked_in → cancelled
checked_in → no_show
```

CHECK constraint: `status IN ('booked', 'checked_in', 'in_consultation', 'completed', 'cancelled', 'no_show')`

### 2.5 Check-in semantics

`checkIn()`:
- Validates status = `booked` (409 otherwise)
- Issues queue token via `TokenIssuer` (row-locked `SELECT ... FOR UPDATE` on `token_counters`)
- Sets `checked_in_by`, `checked_in_at`, increments `lock_version`
- Audit: `appointment.checked_in`

### 2.6 Cancellation semantics

`cancel()`:
- Validates status NOT in `[cancelled, completed]` (409 otherwise)
- Requires `reason` (min 3, max 500 chars)
- Sets `cancel_reason`, increments `lock_version`
- Audit: `appointment.cancelled`

### 2.7 Resource booking (separate from appointments)

`OrchestrationController::bookResource()`:
- Generic resource time-block booking (OT, imaging, equipment, room, bed)
- App-level overlap check (no DB exclusion constraint)
- `resource_type IN ('ot', 'imaging', 'equipment', 'room', 'bed')`
- Audit: `resource.booked`

### 2.8 Queue management

Two queue systems exist:
1. **Appointment-based queue** (`appointments` table with `token_no`) — used by OPD/front-desk
2. **Orchestration queue** (`queue_entries` table) — department-level with priority, used by clinical flow

### 2.9 Permissions

| Permission | Scope |
|---|---|
| `schedule:view` | View schedules, exceptions, availability |
| `schedule:manage` | Create/manage templates and exceptions |
| `appointment:view` | View appointments |
| `appointment:book` | Book appointments |
| `appointment:checkin` | Check patients in |
| `appointment:cancel` | Cancel appointments |
| `queue:view` | View queue |

### 2.10 RLS

All scheduling tables: TENANT_FACILITY tier, ENABLE + FORCE RLS, `swasthya_rls_*()` SECURITY DEFINER helpers reading from `request.jwt.claims`.

## 3. Remaining Scheduling Gaps

### Already implemented / working

- Schedule templates (weekly recurring)
- Schedule exceptions (leave, holiday, block)
- Derived availability (never stale)
- Appointment booking with double-booking prevention
- Check-in with row-locked token issuance
- Cancellation with reason
- Doctor weekly schedule view + bulk update
- Department schedule view
- Resource booking (generic time-block)
- Queue management (two systems)
- RLS on all scheduling tables
- RBAC permissions for scheduling operations

### Hardened (by prior phases)

- Tenant/facility isolation (RLS)
- Concurrency-safe booking (partial unique index)
- Token issuance (row-locked)

### Missing — Enterprise scheduling gaps

| # | Gap | Impact | Prompt § |
|---|---|---|---|
| 1 | **No specialty-aware scheduling** — `staff.specialty` exists but doesn't influence availability derivation, slot generation, or booking validation | Specialty clinics cannot enforce provider-specialty-service-location alignment | §8 |
| 2 | **No service-duration-aware slots** — `services.default_duration_minutes` exists but `ScheduleTemplate.slot_minutes` is used independently; no linkage | Service duration not derived from authoritative config | §9 |
| 3 | **No multi-resource atomic booking** — `resource_bookings` exist separately from `appointments`; no atomic reservation of provider+room+equipment | Procedure/diagnostic bookings cannot guarantee all resources atomically | §12, §13 |
| 4 | **No resource availability derivation** — `resource_bookings` use app-level overlap check (no DB exclusion constraint); resource availability not derived from same engine as provider availability | Resource double-booking possible under race | §11 |
| 5 | **No capacity management beyond per-template** — `schedule_templates.capacity` is per-slot; no facility/department/provider-level capacity limits | No operational capacity controls | §24, §25 |
| 6 | **No overbooking policy** — no overbooking allowance configuration | Cannot control overbooking behavior | §26 |
| 7 | **No waitlist** — no waitlist table or workflow | Patients cannot be queued for future availability | §28, §29 |
| 8 | **No recurring appointments** — no series generation; `follow_ups` exist but are one-at-a-time | Recurring treatment plans cannot be batch-scheduled | §30, §31 |
| 9 | **No appointment rescheduling** — no reschedule endpoint; cancelling + re-booking is the only path | No provenance preservation for reschedules | §32, §33 |
| 10 | **No no-show workflow** — `no_show` status exists in CHECK constraint but no endpoint or workflow to set it | No-show tracking incomplete | §35 |
| 11 | **No appointment history/provenance** — no audit trail of schedule changes (original time, reschedule count, etc.) | Cannot answer "what was scheduled, when, by whom, and what changed" | §33 |
| 12 | **No provider leave integration** — `schedule_exceptions` with `reason='leave'` exist but no connection to staff `on_leave` status; exceptions kill the whole day (no partial-day leave) | Provider leave requires manual exception creation per day | §21 |
| 13 | **No facility closure** — no facility-level closure model; facility closures require per-provider exceptions | Facility closure is operationally expensive | §22 |
| 14 | **No room/resource closure** — no mechanism to mark a room unavailable and identify affected appointments | Room maintenance cannot discover affected appointments | §23 |
| 15 | **No blackout periods** — `schedule_exceptions` support day-level blocks only; no intra-day blackouts (lunch, meetings) | Cannot block partial days | §19 |
| 16 | **No appointment reminders** — no reminder infrastructure for appointments | No automated patient/staff reminders | §51, §52 |
| 17 | **No status transition safety beyond CHECK** — no application-level state machine enforcing valid transitions; relies on controller checks | Invalid transitions possible via direct DB manipulation | §39 |
| 18 | **No resource overlap constraint** — `resource_bookings` use app-level overlap check (not DB exclusion constraint) | Race condition possible on resource booking | §15 |
| 19 | **SpecialtyProfile/SpecialtyAssessment models have namespace error** — `AppModels` instead of `App\Models` | Specialty framework stubs non-functional | — |
| 20 | **Dual queue systems** — appointment-based queue and orchestration queue are disconnected | Queue fragmentation | §37 |

## 4. Final Scheduling Domain Model

```
Organization
  └─ Facility
       ├─ Department
       │    ├─ Staff (provider)
       │    │    ├─ ScheduleTemplate (weekly recurring)
       │    │    ├─ ScheduleException (leave/holiday/block)
       │    │    └─ Appointment ← patient × provider × slot
       │    └─ Service (with default_duration_minutes)
       ├─ Ward → Room → Bed
       ├─ Theatre (OT scheduling)
       ├─ Modality (radiology scheduling)
       └─ Asset/Equipment
```

## 5–48. Sections deferred

The remaining sections (5-48) of the final STOP report will be completed after the hardening phase implements verified gaps. This discovery-only report establishes the authoritative baseline and gap list.

## 49. Next Remaining Feature

The highest-priority verified gap is **multi-resource atomic booking** (Gap #3/#4) — without it, procedure/diagnostic bookings have no atomicity guarantee. However, the user should decide whether to proceed with hardening or stop here.

**DO NOT IMPLEMENT IT.**

Stop after this report.
