# QUEUE MANAGEMENT — STOP REPORT

**Date:** 2026-09-04
**Discipline:** 80-stage hardening, backend-first
**Scope:** Queue entry state machine, race-safe call-next, department transfer, skip/recall, no-show, cancel, audit history

---

## Stage A — Discovery

| Finding | Detail |
|---------|--------|
| Two queue systems exist | (1) Appointment-based: `appointments.token_no`, `TokenIssuer`, `AppointmentController::queue()`. (2) Orchestration queue: `queue_entries` table, `OrchestrationController` |
| Call-next had no concurrency guard | `callNext()` ran outside a transaction with no `FOR UPDATE` — two concurrent callers could pick the same patient |
| No state machine | `queue_entries.status` was a free-form string with no transition validation |
| No audit trail | Queue state changes were not recorded anywhere |
| `clinical:manage` permission missing | The route middleware required it, but it was never defined in the seeder |

---

## Stage B — Contract Capture

### New Endpoints

| Method | Route | Description | Middleware |
|--------|-------|-------------|-----------|
| POST | `orchestration/queue/{entry}/cancel` | Cancel a queue entry with reason | `authorize:clinical:manage` |
| POST | `orchestration/queue/{entry}/no-show` | Mark patient as no-show | `authorize:clinical:manage` |
| POST | `orchestration/queue/{entry}/skip` | Skip a called patient (called → skipped) | `authorize:clinical:manage` |
| POST | `orchestration/queue/{entry}/recall` | Recall a skipped patient (skipped → waiting) | `authorize:clinical:manage` |
| POST | `orchestration/queue/{entry}/transfer` | Transfer to another department (atomic) | `authorize:clinical:manage` |

### State Machine

```
waiting → called → in_progress → completed
waiting → cancelled
waiting → no_show
called → skipped → waiting (re-queued)
called → recalled → waiting (re-queued)
called → cancelled
```

### New Database Objects

| Object | Type | Purpose |
|--------|------|---------|
| `queue_entry_history` | Table | Append-only audit trail for every queue state transition |
| `idx_queue_entries_call_next` | Partial index | Optimized query for call-next (tenant, department, status, priority, token_number WHERE status = 'waiting') |

---

## Stage C — Gap Analysis & Hardening

### 1. Race-Safe Call-Next (CRITICAL)

**Before:** `callNext()` ran outside a transaction. Two concurrent HTTP requests could `SELECT` the same row and both call the same patient.

**After:** Wrapped in `DB::transaction()` + `lockForUpdate()`. The first caller locks the row; the second blocks and sees an updated status, selecting the next available patient.

### 2. State Machine (QueueEntry)

Added `ALLOWED_TRANSITIONS` map, `canTransitionTo()`, `transitionTo()`. Every controller action validates the transition before writing. Invalid transitions throw `InvalidArgumentException` (test) or return 409 CONFLICT (HTTP).

### 3. Department Transfer

`transfer()` atomically: (a) cancels the source entry, (b) creates a new entry in the destination department with the same priority, (c) records `transferred_out` and `transferred_in` history. Same-department transfers are rejected (409).

### 4. Skip/Recall

`skip()` transitions called → skipped. `recall()` transitions skipped → waiting (re-queued). History records both actions.

### 5. No-Show / Cancel

`noShow()` and `cancelEntry()` enforce valid state transitions and record history with the actor and reason.

### 6. Audit History (queue_entry_history)

Every state transition writes to `queue_entry_history` with: tenant, facility, queue_entry, action, from_status, to_status, department (for transfers), actor_id, reason, metadata, created_at. The table is append-only (no UPDATE/DELETE policies). TENANT RLS enabled + forced.

### 7. Permission Seeding

Added `clinical:manage` permission to the RBAC catalog (domain: queue) and assigned it to `org_admin` and `hospital_admin` roles.

---

## Stage D — Files Modified

| File | Change |
|------|--------|
| `app/Models/QueueEntry.php` | State machine, `recordHistory()`, `generateQueueCode()`, new statuses (skipped, recalled) |
| `app/Models/QueueEntryHistory.php` | **NEW** — append-only audit trail model |
| `app/Http/Controllers/Api/OrchestrationController.php` | Race-safe `callNext`, new endpoints (cancel, no-show, skip, recall, transfer) |
| `database/migrations/2026_09_04_200000_harden_queue_management.php` | `queue_entry_history` table + RLS + call-next index |
| `database/Seeders/RolePermissionSeeder.php` | `clinical:manage` permission + assignment to org_admin/hospital_admin |
| `routes/api.php` | 5 new routes (cancel, no-show, skip, recall, transfer) |
| `tests/Feature/QueueEnterpriseTest.php` | **NEW** — 12 tests covering all hardening |

---

## Stage E — Test Results

```
QueueEnterpriseTest:    12 passed (53 assertions)
SchedulingEnterpriseTest: 11 passed (45 assertions)
AppointmentBookingTest:    6 passed (39 assertions)
─────────────────────────────────────────────────
TOTAL:                   29 passed (137 assertions)
Frontend typecheck:       PASS (tsc -b --noEmit)
Pint:                     PASS
```

---

## Stage F — What We Did NOT Do (Intentionally)

1. **No merge of orchestration queue into appointment queue** — the two systems serve different purposes (walk-in queue vs. scheduled queue); merging would be a design change, not a hardening.
2. **No WebSocket push for queue updates** — infrastructure concern, not a correctness gap.
3. **No queue analytics/reporting** — feature work, not hardening.
4. **No integration with appointment check-in** — out of scope for queue management hardening.
