<?php

use App\Models\Department;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\QueueEntry;
use App\Models\QueueEntryHistory;
use Tests\Support\Identity;

/**
 * Queue management enterprise hardening — state machine, race-safe call-next,
 * transfer, skip/recall, no-show, history.
 */
beforeEach(function (): void {
    seedIdentity();
});

function makeDepartment(Organization $org, Facility $facility): Department
{
    return Department::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
    ]);
}

function enqueuePatient(Organization $org, Facility $facility, Department $dept, Patient $patient, string $priority = 'normal'): QueueEntry
{
    return QueueEntry::create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'department' => $dept->name,
        'queue_code' => QueueEntry::generateQueueCode(),
        'patient_id' => $patient->getKey(),
        'priority' => $priority,
        'status' => QueueEntry::STATUS_WAITING,
        'token_number' => QueueEntry::where('tenant_id', $org->getKey())
            ->where('department', $dept->name)
            ->whereDate('created_at', now()->toDateString())
            ->max('token_number') + 1,
    ]);
}

// ── State machine ─────────────────────────────────────────────────────

it('enforces valid queue state transitions', function () {
    $entry = new QueueEntry;
    $entry->status = QueueEntry::STATUS_WAITING;

    expect($entry->canTransitionTo(QueueEntry::STATUS_CALLED))->toBeTrue();
    expect($entry->canTransitionTo(QueueEntry::STATUS_CANCELLED))->toBeTrue();
    expect($entry->canTransitionTo(QueueEntry::STATUS_NO_SHOW))->toBeTrue();
    expect($entry->canTransitionTo(QueueEntry::STATUS_COMPLETED))->toBeFalse();
    expect($entry->canTransitionTo(QueueEntry::STATUS_IN_PROGRESS))->toBeFalse();

    $entry->transitionTo(QueueEntry::STATUS_CALLED);
    expect($entry->status)->toBe(QueueEntry::STATUS_CALLED);
    expect($entry->canTransitionTo(QueueEntry::STATUS_IN_PROGRESS))->toBeTrue();
    expect($entry->canTransitionTo(QueueEntry::STATUS_SKIPPED))->toBeTrue();
    expect($entry->canTransitionTo(QueueEntry::STATUS_CANCELLED))->toBeTrue();

    $entry->transitionTo(QueueEntry::STATUS_IN_PROGRESS);
    expect($entry->canTransitionTo(QueueEntry::STATUS_COMPLETED))->toBeTrue();
    expect($entry->canTransitionTo(QueueEntry::STATUS_CALLED))->toBeFalse();

    $entry->transitionTo(QueueEntry::STATUS_COMPLETED);
    // Terminal state
    expect($entry->canTransitionTo(QueueEntry::STATUS_WAITING))->toBeFalse();
});

it('rejects invalid queue transition with exception', function () {
    $entry = new QueueEntry;
    $entry->status = QueueEntry::STATUS_COMPLETED;

    $entry->transitionTo(QueueEntry::STATUS_WAITING);
})->throws(InvalidArgumentException::class, 'Cannot transition queue entry from [completed] to [waiting].');

// ── Call-next race safety ─────────────────────────────────────────────

it('call-next is race-safe: two concurrent callers get different patients', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patientA = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientB = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $entryA = enqueuePatient($org, $facility, $dept, $patientA);
    $entryB = enqueuePatient($org, $facility, $dept, $patientB);

    // Simulate two concurrent call-next requests
    $result1 = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$dept->name.'/call-next')
        ->assertOk();
    $calledId1 = $result1->json('data.id');

    $result2 = $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$dept->name.'/call-next')
        ->assertOk();
    $calledId2 = $result2->json('data.id');

    // Different patients called
    expect($calledId1)->not->toBe($calledId2);

    // Both are now in 'called' status
    $this->assertDatabaseHas('queue_entries', ['id' => $calledId1, 'status' => 'called']);
    $this->assertDatabaseHas('queue_entries', ['id' => $calledId2, 'status' => 'called']);
});

it('call-next returns empty when no patients waiting', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$dept->name.'/call-next')
        ->assertOk()
        ->assertJsonPath('data.message', 'No patients waiting');
});

it('call-next respects priority ordering', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patientNormal = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $patientUrgent = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);

    $entryNormal = enqueuePatient($org, $facility, $dept, $patientNormal, 'normal');
    $entryUrgent = enqueuePatient($org, $facility, $dept, $patientUrgent, 'urgent');

    // Urgent should be called first despite normal being enqueued first
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$dept->name.'/call-next')
        ->assertOk()
        ->assertJsonPath('data.id', $entryUrgent->getKey());
});

// ── Transfer ──────────────────────────────────────────────────────────

it('transfers a queue entry to another department atomically', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $deptA = makeDepartment($org, $facility);
    $deptB = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $deptA, $patient);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/transfer', [
            'to_department' => $deptB->name,
            'reason' => 'Specialist required',
        ])
        ->assertCreated()
        ->assertJsonPath('data.department', $deptB->name)
        ->assertJsonPath('data.status', 'waiting');

    // Source entry is cancelled
    $this->assertDatabaseHas('queue_entries', [
        'id' => $entry->getKey(),
        'status' => 'cancelled',
    ]);

    // New entry exists in destination department
    $newEntry = QueueEntry::where('department', $deptB->name)
        ->where('patient_id', $patient->getKey())
        ->first();
    expect($newEntry)->not->toBeNull()
        ->and($newEntry->status)->toBe('waiting')
        ->and($newEntry->priority)->toBe($entry->priority);

    // History recorded for both entries
    expect(QueueEntryHistory::where('queue_entry_id', $entry->getKey())->where('action', 'transferred_out')->exists())->toBeTrue()
        ->and(QueueEntryHistory::where('queue_entry_id', $newEntry->getKey())->where('action', 'transferred_in')->exists())->toBeTrue();
});

it('refuses transfer to same department', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $dept, $patient);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/transfer', [
            'to_department' => $dept->name,
        ])
        ->assertStatus(409);
});

// ── Skip / Recall ─────────────────────────────────────────────────────

it('skips a called entry and recalls it back to waiting', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $dept, $patient);

    // Call the patient
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$dept->name.'/call-next')
        ->assertOk();

    // Skip
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/skip', [
            'reason' => 'Patient not ready',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'skipped');

    // Recall
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/recall')
        ->assertOk()
        ->assertJsonPath('data.status', 'waiting')
        ->assertJsonPath('data.called_at', null);

    // History has both skip and recall
    expect(QueueEntryHistory::where('queue_entry_id', $entry->getKey())->where('action', 'skipped')->exists())->toBeTrue()
        ->and(QueueEntryHistory::where('queue_entry_id', $entry->getKey())->where('action', 'recalled')->exists())->toBeTrue();
});

it('refuses skip on a waiting entry', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $dept, $patient);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/skip')
        ->assertStatus(409);
});

// ── No-show ───────────────────────────────────────────────────────────

it('marks a queue entry as no-show', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $dept, $patient);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/no-show')
        ->assertOk()
        ->assertJsonPath('data.status', 'no_show');

    expect(QueueEntryHistory::where('queue_entry_id', $entry->getKey())->where('action', 'no_show')->exists())->toBeTrue();
});

// ── Cancel ────────────────────────────────────────────────────────────

it('cancels a queue entry with reason', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $dept, $patient);

    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/cancel', [
            'reason' => 'Patient left',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect(QueueEntryHistory::where('queue_entry_id', $entry->getKey())->where('action', 'cancelled')->exists())->toBeTrue();
});

// ── History ───────────────────────────────────────────────────────────

it('records history for every queue state transition', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $admin = Identity::user();
    Identity::assign($admin, 'org_admin', $org);
    $dept = makeDepartment($org, $facility);

    $patient = Patient::factory()->create(['tenant_id' => $org->getKey(), 'facility_id' => $facility->getKey()]);
    $entry = enqueuePatient($org, $facility, $dept, $patient);

    // Call
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$dept->name.'/call-next')
        ->assertOk();

    // Start
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/start')
        ->assertOk();

    // Complete
    $this->withToken(Identity::tokenFor($admin))
        ->postJson('/api/v1/notifications/orchestration/queue/'.$entry->getKey().'/complete')
        ->assertOk();

    // All 3 history entries exist (called, started, completed)
    $history = QueueEntryHistory::where('queue_entry_id', $entry->getKey())->orderBy('created_at')->get();
    expect($history)->toHaveCount(3)
        ->and($history->pluck('action')->toArray())->toBe(['called', 'started', 'completed']);
});
