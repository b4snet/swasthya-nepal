<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

/**
 * Phase 3 slice 11 — refund completed/disbursement state (PRODUCT_REQUIREMENTS
 * §6.13, DATABASE.md §3.33): an APPROVED refund request transitions to
 * 'completed' when the money is actually disbursed back to the patient.
 *
 * No payment provider exists or is invented: completion is recorded by the
 * finance officer who hands the money over. The approved request remains the
 * immutable reversing entry — the charge is never mutated and the refundable
 * accounting is unchanged. Completion is CAS-guarded (status + lock_version)
 * — a refund can be disbursed exactly once — and the requester can never
 * complete their own refund (segregation of duties, mirroring approval).
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * A posted charge for the tenant/facility (prefixed to avoid colliding with
 * the RefundAdjustmentTest helper of the same purpose).
 */
function refundDisburseCharge(Organization $org, Facility $facility, int $amountMinor): Charge
{
    return Charge::factory()->create([
        'tenant_id' => $org->getKey(),
        'facility_id' => $facility->getKey(),
        'source_type' => Charge::SOURCE_MANUAL,
        'amount_minor' => $amountMinor,
        'currency' => 'NPR',
        'tax_rate_bps' => 0,
        'status' => Charge::STATUS_POSTED,
    ]);
}

/**
 * Request → approve via the API, returning the approved request.
 */
function refundDisburseApproved($test, Organization $org, Facility $facility, int $amountMinor, ?Charge $charge = null): RefundRequest
{
    $clerk = Identity::user();
    $approver = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    $charge ??= refundDisburseCharge($org, $facility, $amountMinor);

    $test->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => $amountMinor,
            'reasonCode' => 'overcharge',
        ])
        ->assertCreated();

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    $test->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertOk();

    return $request->refresh();
}

it('completes an approved refund: the disbursement is recorded and the charge stays immutable', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_admin', $org, $facility);

    $request = refundDisburseApproved($this, $org, $facility, 4000);
    $charge = Charge::query()->findOrFail($request->charge_id);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertOk()
        ->assertJsonPath('data.status', RefundRequest::STATUS_COMPLETED)
        ->assertJsonPath('data.completedBy', $finance->getKey())
        ->assertJsonPath('data.completedAt', fn (mixed $v) => is_string($v))
        ->assertJsonPath('data.lockVersion', 2);

    $request->refresh();
    expect($request->status)->toBe(RefundRequest::STATUS_COMPLETED)
        ->and($request->completed_by)->toBe($finance->getKey())
        ->and($request->completed_at)->not->toBeNull()
        ->and($request->approved_by)->not->toBeNull();

    // The original charge is untouched — posted, same amount, never mutated.
    $charge->refresh();
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->amount_minor)->toBe(4000);

    // Facts-only audit for the disbursement.
    $event = AuditEvent::query()->where('action', 'refund.completed')->firstOrFail();
    expect($event->payload)
        ->toHaveKey('chargeId', $request->charge_id)
        ->toHaveKey('amountMinor', 4000)
        ->toHaveKey('reasonCode', 'overcharge');
});

it('refuses to complete anything but an approved request (409), with no side effects', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    $finance = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($finance, 'org_admin', $org, $facility);

    $charge = refundDisburseCharge($org, $facility, 1000);

    // A pending request cannot be completed.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 1000,
            'reasonCode' => 'adjustment',
        ])
        ->assertCreated();
    $pending = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$pending->getKey().'/complete')
        ->assertStatus(409);

    // A rejected request cannot be completed either.
    $approver = Identity::user();
    Identity::assign($approver, 'org_admin', $org, $facility);
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$pending->getKey().'/reject', ['rejectionReason' => 'Not warranted.'])
        ->assertOk();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$pending->getKey().'/complete')
        ->assertStatus(409);

    expect(RefundRequest::query()->where('status', RefundRequest::STATUS_COMPLETED)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'refund.completed')->count())->toBe(0);
});

it('enforces segregation of duties: the requester can never complete their own refund', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $financeRequester = Identity::user();
    $approver = Identity::user();
    Identity::assign($financeRequester, 'org_admin', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    $charge = refundDisburseCharge($org, $facility, 2000);

    // The finance officer requests the refund (org_admin holds billing:refund
    // AND billing:refund-approve — so the route gate passes and the service's
    // segregation check is what refuses the requester).
    $this->withToken(Identity::tokenFor($financeRequester))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 2000,
            'reasonCode' => 'duplicate_charge',
        ])
        ->assertCreated();
    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    // A different officer approves.
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertOk();

    // The requester cannot disburse their own refund.
    $this->withToken(Identity::tokenFor($financeRequester))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertStatus(403);

    expect($request->refresh()->status)->toBe(RefundRequest::STATUS_APPROVED)
        ->and(AuditEvent::query()->where('action', 'refund.completed')->count())->toBe(0);
});

it('prevents duplicate disbursement: second completion is a 409, one audit', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_admin', $org, $facility);

    $request = refundDisburseApproved($this, $org, $facility, 1500);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertOk();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertStatus(409);

    expect(RefundRequest::query()->where('status', RefundRequest::STATUS_COMPLETED)->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'refund.completed')->count())->toBe(1);
});

it('wins the concurrent completion race via CAS: stale actor affects zero rows', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_admin', $org, $facility);

    $request = refundDisburseApproved($this, $org, $facility, 2500);
    $staleVersion = $request->lock_version;

    // Two CAS updates with the same snapshot: exactly one wins.
    $first = DB::table('refund_requests')
        ->where('id', $request->getKey())
        ->where('status', RefundRequest::STATUS_APPROVED)
        ->where('lock_version', $staleVersion)
        ->update([
            'status' => RefundRequest::STATUS_COMPLETED,
            'completed_by' => $finance->getKey(),
            'completed_at' => now(),
            'lock_version' => $staleVersion + 1,
            'updated_at' => now(),
        ]);

    $second = DB::table('refund_requests')
        ->where('id', $request->getKey())
        ->where('status', RefundRequest::STATUS_APPROVED)
        ->where('lock_version', $staleVersion)
        ->update([
            'status' => RefundRequest::STATUS_COMPLETED,
            'completed_by' => $finance->getKey(),
            'completed_at' => now(),
            'lock_version' => $staleVersion + 1,
            'updated_at' => now(),
        ]);

    expect($first + $second)->toBe(1)
        ->and(RefundRequest::query()->where('status', RefundRequest::STATUS_COMPLETED)->count())->toBe(1);
});

it('keeps the refundable accounting intact after completion (financial integrity)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_admin', $org, $facility);

    $charge = refundDisburseCharge($org, $facility, 10000);
    $request = refundDisburseApproved($this, $org, $facility, 4000, $charge);

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertOk();

    // The remaining refundable amount (10000 − 4000) is unchanged by
    // completion — the amount was already reserved at approval.
    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 6001,
            'reasonCode' => 'overcharge',
        ])
        ->assertStatus(422);
});

it('enforces authorization: billing clerk cannot complete; unauthenticated is 401', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $request = refundDisburseApproved($this, $org, $facility, 3000);

    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertStatus(403);

    expect($request->refresh()->status)->toBe(RefundRequest::STATUS_APPROVED);

    $this->flushHeaders();
    $this->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')->assertStatus(401);
});

it('enforces cross-tenant and cross-facility isolation with no existence leak', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $requestA = refundDisburseApproved($this, $orgA, $facilityA, 5000);

    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);
    $financeB = Identity::user();
    Identity::assign($financeB, 'org_admin', $orgB, $facilityB);

    // Cross-tenant: read invisible (404), write denied (403), data untouched.
    $this->withToken(Identity::tokenFor($financeB))
        ->postJson('/api/v1/refund-requests/'.$requestA->getKey().'/complete')
        ->assertStatus(403);

    // Cross-facility within the same tenant: same result.
    $facilityA2 = Identity::facility($orgA);
    $financeA2 = Identity::user();
    Identity::assign($financeA2, 'org_admin', $orgA, $facilityA2);
    $this->withToken(Identity::tokenFor($financeA2))
        ->postJson('/api/v1/refund-requests/'.$requestA->getKey().'/complete')
        ->assertStatus(403);

    expect($requestA->refresh()->status)->toBe(RefundRequest::STATUS_APPROVED)
        ->and(RefundRequest::query()->where('status', RefundRequest::STATUS_COMPLETED)->count())->toBe(0)
        ->and(AuditEvent::query()->where('action', 'refund.completed')->count())->toBe(0);
});

it('keeps patient identifiers and reason text out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $finance = Identity::user();
    Identity::assign($finance, 'org_admin', $org, $facility);

    $charge = refundDisburseCharge($org, $facility, 10000);
    $patient = $charge->patient;
    $patientName = $patient->full_name;

    $clerk = Identity::user();
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 4000,
            'reasonCode' => 'patient_request',
            'reasonNote' => 'Patient '.$patientName.' overpaid at the counter and requested a refund.',
        ])
        ->assertCreated();
    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    $approver = Identity::user();
    Identity::assign($approver, 'org_admin', $org, $facility);
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertOk();

    $this->withToken(Identity::tokenFor($finance))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/complete')
        ->assertOk();

    // No patient name or reason-note text in ANY audit payload — facts only.
    foreach (AuditEvent::query()->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('overpaid')
            ->and($encoded)->not->toContain('requested a refund');
    }

    $event = AuditEvent::query()->where('action', 'refund.completed')->firstOrFail();
    expect($event->payload)
        ->toHaveKey('chargeId', $request->charge_id)
        ->toHaveKey('amountMinor', 4000)
        ->toHaveKey('reasonCode', 'patient_request');
});
