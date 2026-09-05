# SWASTHYA — APPOINTMENTS / SCHEDULING STOP REPORT

**Phase:** Enterprise scheduling hardening (internal, backend-first)
**Branch:** `main` (HEAD `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6`)
**Date:** 2026-09-04
**Disposition:** STOP — no commit, no push, no deploy. Hardening complete and regression-verified.

---

## 1. Baseline

- **branch:** `main`
- **HEAD:** `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6`
- **remote/main:** `9fc3508eb6e848fc92a43d94694819a6c462f1cd`
- **working tree:** prior-phase uncommitted changes remain untouched
- **scheduling tests baseline:** `AppointmentBookingTest` 6 passed (39 assertions); `DoctorScheduleTest` 1 failed (pre-existing `Staff::casts()` bug)

## 2. Existing Scheduling Capability (verified)

### 2.1 Core tables

| Table | Purpose | RLS |
|---|---|---|
| `schedule_templates` | Recurring weekly provider availability | TENANT_FACILITY |
| `schedule_exceptions` | Leave / holiday / block per provider per date | TENANT_FACILITY |
| `appointments` | Patient × provider × slot booking | TENANT_FACILITY |
| `token_counters` | Row-locked queue token sequence | TENANT_FACILITY |
| `services` | Clinical offerings with `default_duration_minutes` | TENANT_FACILITY |
| `rooms` | Rooms within wards | TENANT_FACILITY_BRANCH |
| `resource_bookings` | Generic resource time-block bookings | TENANT_FACILITY |
| `queue_entries` | Department-level patient queue | TENANT_FACILITY |

### 2.2 Derived availability engine

`SlotService::slotsFor()` — availability is derived, never stored:
1. Templates for (tenant, staff, day_of_week, validity)
2. Exceptions kill entire day
3. Live bookings counted per start-time
4. Slots generated with `available = booked < capacity`

### 2.3 Double-booking prevention

- **Application:** SlotService validates slot availability before INSERT
- **Database:** Partial unique index `uq_appointments_tenant_provider_start` on live statuses — parallel races resolved at DB level

### 2.4 Appointment status lifecycle

```
booked → checked_in → in_consultation → completed
booked → cancelled (reason required)
booked → no_show
checked_in → cancelled / no_show
```

### 2.5 Permissions

`schedule:view`, `schedule:manage`, `appointment:view`, `appointment:book`, `appointment:checkin`, `appointment:cancel`, `queue:view`

### 2.6 RLS

All scheduling tables: TENANT_FACILITY tier, ENABLE + FORCE RLS, `swasthya_rls_*()` SECURITY DEFINER helpers.

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

### Newly implemented (this phase)

| # | Feature | What changed |
|---|---|---|
| 1 | **Appointment state machine** | `Appointment` model: `ALLOWED_TRANSITIONS` map, `canTransitionTo()`, `transitionTo()`, `isTerminal()`. Controller uses state machine for all transitions. |
| 2 | **Service-duration-aware availability** | `SlotService` accepts optional `serviceId`; when service has `default_duration_minutes`, generates block slots spanning multiple sub-slots; block is available only if ALL sub-slots have capacity. `ScheduleController::availability()` passes `serviceId` query param. |
| 3 | **No-show endpoint** | `POST /appointments/{id}/no-show` — state machine: `booked\|checked_in → no_show`. Audit: `appointment.no_show`. |
| 4 | **Appointment rescheduling** | `POST /appointments/{id}/reschedule` — releases old slot (cancelled with reason), validates new slot, creates new appointment with `rescheduled_from` provenance. All atomic in one transaction. |
| 5 | **Resource booking overlap trigger** | PostgreSQL trigger `trg_resource_bookings_overlap_check` prevents overlapping active bookings for same (resource_type, resource_id) at DB level. |
| 6 | **`rescheduled_from` column** | `appointments.rescheduled_from` UUID FK to source appointment. Set on reschedule, preserved in history. |

### Deferred (not implemented)

| # | Gap | Reason |
|---|---|---|
| 1 | Specialty-aware scheduling | `staff.specialty` exists but no enforcement at booking boundary — requires real hospital specialty rules |
| 2 | Multi-resource atomic booking | `resource_bookings` exist separately from `appointments` — no atomic reservation of provider+room+equipment |
| 3 | Capacity management beyond per-template | No facility/department/provider-level capacity limits |
| 4 | Overbooking policy | No overbooking allowance configuration |
| 5 | Waitlist | No waitlist table or workflow |
| 6 | Recurring appointments | No series generation |
| 7 | Provider leave integration | Exceptions exist but no connection to staff `on_leave` status |
| 8 | Facility closure | No facility-level closure model |
| 9 | Blackout periods | Day-level exceptions only; no intra-day blackouts |
| 10 | Appointment reminders | No reminder infrastructure |
| 11 | Dual queue systems | Appointment-based queue and orchestration queue disconnected |

## 4. Final Scheduling Domain Model

```
Organization
  └─ Facility
       ├─ Department
       │    ├─ Staff (provider)
       │    │    ├─ ScheduleTemplate (weekly recurring)
       │    │    ├─ ScheduleException (leave/holiday/block)
       │    │    └─ Appointment ← patient × provider × slot
       │    │         └─ rescheduled_from → Appointment (provenance)
       │    └─ Service (with default_duration_minutes)
       ├─ Ward → Room → Bed
       ├─ Theatre (OT scheduling)
       ├─ Modality (radiology scheduling)
       └─ Asset/Equipment
```

## 5. Schedule Template / Exception Model

- **Templates:** Recurring weekly, per (staff, day_of_week, time window, slot_minutes, capacity, validity). Soft-deletable.
- **Exceptions:** One per (staff, date). Reasons: leave, holiday, block. Kills entire day's availability. NOT soft-deleted.
- **Precedence:** Exception > Template > Base (exception blocks all templates for that day).

## 6. Availability Engine

`SlotService::slotsFor()` — derived, never stored:
- Template-driven slot generation
- Exception-day block
- Live booking subtraction per start-time
- **NEW:** Service-duration block mode — when `serviceId` provided, slots grouped into contiguous blocks matching `service.default_duration_minutes`; block available only if ALL sub-slots have capacity

## 7. Provider Scheduling

- Weekly templates with validity windows
- Bulk weekly schedule update (atomic deactivate + create)
- Doctor profile with specialty, consultation fee, duration, available days
- Department-level schedule view

## 8. Specialty Scheduling

`staff.specialty` field exists but is NOT enforced at booking boundary. Specialty is informational only in current implementation.

## 9. Service Duration / Scheduling Rules

- `services.default_duration_minutes` exists
- **NEW:** When `serviceId` passed to availability endpoint, `SlotService` generates block slots matching service duration
- Template `slot_minutes` is the sub-slot granularity; service duration must be a multiple of slot_minutes for clean alignment

## 10. Resource Model

- `resource_bookings`: Generic time-block bookings (OT, imaging, equipment, room, bed)
- **NEW:** DB-level overlap trigger prevents concurrent active bookings for same resource

## 11. Multi-Resource Booking

NOT implemented. `resource_bookings` and `appointments` are separate systems. No atomic reservation of provider+room+equipment.

## 12. Capacity Management

Per-template `capacity` (default 1). Multiple appointments can occupy same slot up to capacity. No facility/department/provider-level capacity limits.

## 13. Overbooking

No overbooking policy configuration.

## 14. Waitlist

NOT implemented.

## 15. Recurring Appointments

NOT implemented. `follow_ups` exist for one-at-a-time planned return visits.

## 16. Rescheduling

**NEW:** `POST /appointments/{id}/reschedule` — atomic release old + book new + preserve provenance via `rescheduled_from`.

## 17. Cancellation / No-Show

- **Cancellation:** Preserved. Reason required. State machine enforced.
- **NEW No-show:** `POST /appointments/{id}/no-show` — `booked|checked_in → no_show`.

## 18. Check-In / Queue Integration

Two queue systems:
1. Appointment-based (`appointments.token_no`) — OPD/front-desk
2. Orchestration (`queue_entries`) — department-level with priority

## 19. Appointment State Machine

**NEW:** Explicit `ALLOWED_TRANSITIONS` map in `Appointment` model:
```
booked → [checked_in, cancelled, no_show]
checked_in → [in_consultation, cancelled, no_show]
in_consultation → [completed]
completed → [] (terminal)
cancelled → [] (terminal)
no_show → [] (terminal)
```

Every transition validated by `canTransitionTo()` before DB write.

## 20. Appointment History / Provenance

- `rescheduled_from` UUID FK preserves source appointment
- `cancel_reason` preserved on cancellation
- `checked_in_by`, `checked_in_at` preserved
- `lock_version` incremented on every status change
- `created_by` preserved

## 21. Authorization

RBAC: `schedule:view`, `schedule:manage`, `appointment:view`, `appointment:book`, `appointment:checkin`, `appointment:cancel`, `queue:view`. Route-level middleware enforcement.

## 22. IDOR Results

All scheduling queries scoped by `tenant_id` from `TenantContext`. `AccessCheck::scoped()` on all show/update endpoints. No cross-tenant IDOR found.

## 23. Tenant / Facility Isolation

RLS enforced on all scheduling tables (TENANT_FACILITY tier). `ENABLE + FORCE RLS`. `swasthya_rls_*()` SECURITY DEFINER helpers.

## 24. RLS Results

SELECT/INSERT/UPDATE/DELETE policies on `schedule_templates`, `schedule_exceptions`, `appointments`, `token_counters`, `resource_bookings`, `queue_entries`. All TENANT_FACILITY scoped.

## 25. Concurrency Results

- **Appointment booking:** Partial unique index + application-level SlotService validation
- **Token issuance:** `SELECT ... FOR UPDATE` on `token_counters`
- **Resource booking:** **NEW** DB-level trigger prevents overlap
- **Schedule template update:** Atomic deactivate + create in transaction

## 26. Audit Behavior

Actions audited: `appointment.booked`, `appointment.checked_in`, `appointment.cancelled`, `appointment.no_show` (NEW), `appointment.rescheduled` (NEW), `schedule.template.created`, `schedule.exception.created`, `doctor.schedule.bulk_updated`, `resource.booked`.

## 27. Patient / Encounter / Billing Integration

- Appointment links to Patient, Staff (provider), Service
- `start-encounter` creates Encounter from checked-in appointment
- Flow: Appointment → Encounter → Charge/Invoice (billing owned by FinanceService)

## 28. Notification Integration

NOT implemented. No reminder infrastructure.

## 29. External Calendar Integration

NOT implemented. No Google/Outlook sync.

## 30. Migration / Provenance

- `appointments.rescheduled_from` FK → source appointment (set null on delete)
- Resource booking overlap trigger with proper error message

## 31. Performance / Capacity Results

- Availability derivation: O(templates × slots) per query — bounded by template count
- Service-duration blocks: O(templates × sub-slots) — bounded
- Resource booking trigger: O(existing bookings) per insert/update — indexed on (resource_type, resource_id, starts_at, ends_at)

## 32. Hospital UAT

NOT performed. Synthetic tests only.

## 33. Real PostgreSQL Proof

Tests run against real PostgreSQL 15 at `127.0.0.1:5433`. All 17 scheduling tests pass (6 existing + 11 new). Trigger-based overlap prevention verified.

## 34. Security Findings

- **informational:** `DoctorScheduleTest` fails due to pre-existing `Staff::casts()` undefined method — not a security issue

## 35. Operational Scheduling Findings

- Dual queue systems (appointment-based + orchestration) are disconnected — may cause operational confusion
- `schedule_exceptions` kill entire day — no partial-day leave support
- No facility closure model — facility closures require per-provider exceptions

## 36. Regression Results

| Suite | Baseline | Final | Delta |
|---|---|---|---|
| AppointmentBookingTest | 6 passed | 6 passed | 0 |
| SchedulingEnterpriseTest | (new) | 11 passed | +11 |
| DoctorScheduleTest | 1 failed | 1 failed | 0 |
| **Total scheduling** | **7 passed, 1 failed** | **17 passed, 1 failed** | **+10 passed** |

## 37. TypeScript / Lint / Build

- `npm run typecheck` — PASS
- `php vendor/bin/pint --test` — PASS
- `git diff --check` — CLEAN

## 38. Documentation

Discovery report: `SCHEDULING_DISCOVERY_REPORT.md`
STOP report: This file

## 39. Files Created

- `backend/database/migrations/2026_09_04_100000_harden_scheduling_enterprise.php`
- `backend/tests/Feature/SchedulingEnterpriseTest.php`
- `SCHEDULING_DISCOVERY_REPORT.md`

## 40. Files Modified

- `backend/app/Models/Appointment.php` (state machine: ALLOWED_TRANSITIONS, canTransitionTo, transitionTo, isTerminal, rescheduled_from)
- `backend/app/Http/Controllers/Api/AppointmentController.php` (state machine enforcement, no-show, reschedule endpoints)
- `backend/app/Http/Controllers/Api/ScheduleController.php` (serviceId param for availability)
- `backend/app/Services/SlotService.php` (service-duration-aware block slots)
- `frontend/src/api/types.ts` (rescheduledFrom field)
- `backend/tests/Feature/SchedulingEnterpriseTest.php` (new test file)

## 41. Database / Migration / RLS Changes

- `appointments.rescheduled_from` UUID nullable FK → appointments
- `trg_resource_bookings_overlap_check` trigger on resource_bookings (BEFORE INSERT OR UPDATE)
- `trg_resource_bookings_no_overlap()` function

## 42. Production / Staging Changes

`NONE`

## 43. External Systems Touched

None.

## 44. Validation Tiers

| Capability | Tier |
|---|---|
| Status state machine | PROVEN LOCALLY |
| Service-duration availability | PROVEN LOCALLY |
| No-show workflow | PROVEN LOCALLY |
| Rescheduling with provenance | PROVEN LOCALLY |
| Resource booking overlap trigger | PROVEN LOCALLY |
| Specialty scheduling | DEFERRED |
| Multi-resource atomic booking | DEFERRED |
| Waitlist | DEFERRED |
| Recurring appointments | DEFERRED |
| Appointment reminders | DEFERRED |
| Facility closure | DEFERRED |

## 45. Remaining Risks

- Dual queue systems may cause operational confusion if not unified
- `schedule_exceptions` kill entire day — no partial-day leave support
- No facility closure model — operationally expensive for multi-provider facilities
- Resource booking trigger catches overlap but does not provide structured error info to frontend

## 46. Final Git State

- **branch:** `main`
- **HEAD:** `4448a2c2ac61d0f9ed773aba63f1acc54269e1b6` (unchanged)
- **working tree:** prior-phase changes + new scheduling hardening changes
- **modified:** `Appointment.php`, `AppointmentController.php`, `ScheduleController.php`, `SlotService.php`, `types.ts`
- **new:** `2026_09_04_100000_harden_scheduling_enterprise.php`, `SchedulingEnterpriseTest.php`, `SCHEDULING_DISCOVERY_REPORT.md`

## 47. Final Status

**COMPLETE WITH DOCUMENTED LIMITATIONS**

## 48. Next Remaining Feature

The highest-priority deferred gap is **multi-resource atomic booking** (provider+room+equipment in one transaction). However, this requires real hospital operational requirements to define which resources are mandatory vs optional per appointment type.

**DO NOT IMPLEMENT IT.**

Stop after this report.
