<?php

use App\Models\AuditEvent;
use App\Models\Charge;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\RefundRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Identity;

/**
 * Phase 3 slice 5 — the billing refund/adjustment workflow (PRODUCT_REQUIREMENTS
 * §6.13, DATABASE.md §3.33): posted charge → refund/adjustment request →
 * authorized approval → immutable reversing entry.
 *
 * The approved request IS the reversal — the original charge is never
 * mutated. The refundable amount is amount_minor − Σ(approved); both
 * creation and approval re-check it under the charge-row lock, so
 * over-refund is impossible even under concurrency. Approval is CAS-guarded
 * (status + lock_version) and requires a distinct approver (segregation of
 * duties).
 */
beforeEach(function (): void {
    seedIdentity();
});

/**
 * A posted charge for the tenant/facility.
 */
function refundCharge(Organization $org, Facility $facility, int $amountMinor): Charge
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

it('requests a refund, approves it, and leaves the posted charge immutable', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    $approver = Identity::user();

    $charge = refundCharge($org, $facility, 10000);

    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    // The clerk requests a partial refund.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 4000,
            'reasonCode' => 'overcharge',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'requested')
        ->assertJsonPath('data.amountMinor', 4000)
        ->assertJsonPath('data.reasonCode', 'overcharge');

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();
    expect($request->status)->toBe(RefundRequest::STATUS_REQUESTED);

    // The approver approves — the request becomes the reversing entry.
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approvedBy', $approver->getKey());

    // The original charge is untouched — posted, same amount, no mutation.
    $charge->refresh();
    expect($charge->status)->toBe(Charge::STATUS_POSTED)
        ->and($charge->amount_minor)->toBe(10000);

    // Audit: requested + approved, facts only.
    expect(AuditEvent::query()->where('action', 'refund.requested')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'refund.approved')->count())->toBe(1);
});

it('rejects a refund request with a mandatory reason and audits the rejection', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    $approver = Identity::user();

    $charge = refundCharge($org, $facility, 10000);

    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 1000,
            'reasonCode' => 'adjustment',
        ])
        ->assertCreated();

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    // Rejection without a reason is refused.
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/reject', [])
        ->assertStatus(422);

    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/reject', [
            'rejectionReason' => 'Charge is correct; no adjustment warranted.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $request->refresh();
    expect($request->rejected_by)->toBe($approver->getKey())
        ->and($request->rejection_reason)->not->toBeNull();

    // A rejected request cannot be approved afterwards.
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertStatus(409);

    expect(AuditEvent::query()->where('action', 'refund.rejected')->count())->toBe(1);
});

it('refuses requests beyond the refundable amount and blocks over-refund at approval', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    $approver = Identity::user();

    $charge = refundCharge($org, $facility, 10000);

    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    // Creation beyond the charge amount is refused.
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 15000,
            'reasonCode' => 'overcharge',
        ])
        ->assertStatus(422);

    // Two requests within the amount are both creatable…
    foreach ([6000, 6000] as $amount) {
        $this->withToken(Identity::tokenFor($clerk))
            ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
                'amountMinor' => $amount,
                'reasonCode' => 'adjustment',
            ])
            ->assertCreated();
    }

    // …but only the first can be approved: the second would exceed 10000.
    $requests = RefundRequest::query()->where('charge_id', $charge->getKey())->orderBy('created_at')->get();
    expect($requests)->toHaveCount(2);

    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$requests[0]->getKey().'/approve')
        ->assertOk();

    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$requests[1]->getKey().'/approve')
        ->assertStatus(422);

    // Totals remain internally consistent: 6000 approved of 10000.
    expect($requests[0]->refresh()->status)->toBe(RefundRequest::STATUS_APPROVED)
        ->and($requests[1]->refresh()->status)->toBe(RefundRequest::STATUS_REQUESTED);
});

it('refuses duplicate approval and wins the CAS race exactly once', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    $approver = Identity::user();

    $charge = refundCharge($org, $facility, 10000);

    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 3000,
            'reasonCode' => 'overcharge',
        ])
        ->assertCreated();

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();
    expect($request->status)->toBe(RefundRequest::STATUS_REQUESTED);

    // The winning approval commits atomically — the exact CAS the service
    // runs: WHERE status AND lock_version match, then advance.
    $winner = DB::table('refund_requests')
        ->where('id', $request->getKey())
        ->where('status', RefundRequest::STATUS_REQUESTED)
        ->where('lock_version', $request->lock_version)
        ->update(['status' => RefundRequest::STATUS_APPROVED, 'lock_version' => $request->lock_version + 1]);

    expect($winner)->toBe(1);

    // A second approver holding the SAME stale snapshot can never advance
    // the request again: the CAS affects zero rows.
    $loser = DB::table('refund_requests')
        ->where('id', $request->getKey())
        ->where('status', RefundRequest::STATUS_REQUESTED)
        ->where('lock_version', $request->lock_version)
        ->update(['status' => RefundRequest::STATUS_APPROVED, 'lock_version' => $request->lock_version + 1]);

    expect($loser)->toBe(0);

    // And the losing HTTP request — arriving after the winner committed —
    // fails safely with CONFLICT and changes nothing.
    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'CONFLICT');

    // The winner committed exactly once; the losing HTTP request changed
    // nothing (the direct-CAS winner bypasses AuditLogger, so no approved
    // event is emitted by the test's own write — the status is the proof).
    expect(RefundRequest::query()->findOrFail($request->getKey())->status)->toBe(RefundRequest::STATUS_APPROVED)
        ->and(RefundRequest::query()->where('status', RefundRequest::STATUS_APPROVED)->count())->toBe(1);
});

it('blocks the requester from approving their own request (segregation of duties)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();

    $charge = refundCharge($org, $facility, 10000);

    // The clerk holds BOTH request and approve permissions (org_admin), but
    // the service still refuses self-approval.
    Identity::assign($clerk, 'org_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 1000,
            'reasonCode' => 'adjustment',
        ])
        ->assertCreated();

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertStatus(403);

    expect($request->refresh()->status)->toBe(RefundRequest::STATUS_REQUESTED);
});

it('refuses refunds on a voided or missing charge', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();

    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    // A missing charge is a 404 (route binding fails before the controller).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.(string) Str::uuid().'/refunds', [
            'amountMinor' => 500,
            'reasonCode' => 'adjustment',
        ])
        ->assertStatus(404);

    // A voided charge cannot be refunded.
    $charge = refundCharge($org, $facility, 5000);
    $charge->update(['status' => Charge::STATUS_VOIDED, 'void_reason' => 'Posted in error']);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 500,
            'reasonCode' => 'adjustment',
        ])
        ->assertStatus(409);
});

it('enforces RBAC: clinical and front-desk roles cannot request or approve refunds', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();

    $charge = refundCharge($org, $facility, 5000);

    // A doctor has billing:view only — cannot request a refund.
    $doctor = Identity::user();
    Identity::assign($doctor, 'doctor', $org, $facility);
    $this->withToken(Identity::tokenFor($doctor))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 500,
            'reasonCode' => 'adjustment',
        ])
        ->assertStatus(403);

    // A nurse cannot either.
    $nurse = Identity::user();
    Identity::assign($nurse, 'nurse', $org, $facility);
    $this->withToken(Identity::tokenFor($nurse))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 500,
            'reasonCode' => 'adjustment',
        ])
        ->assertStatus(403);

    // The billing clerk can request…
    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 500,
            'reasonCode' => 'adjustment',
        ])
        ->assertCreated();

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    // …but the clerk does NOT hold billing:refund-approve — approval is the
    // admin's job (segregation of duties).
    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertStatus(403);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/reject', [
            'rejectionReason' => 'No.',
        ])
        ->assertStatus(403);
});

it('requires authentication (401)', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $charge = refundCharge($org, $facility, 5000);

    $this->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
        'amountMinor' => 500,
        'reasonCode' => 'adjustment',
    ])->assertStatus(401);
});

it('enforces cross-tenant isolation for the whole refund surface', function () {
    $orgA = Identity::organization();
    $facilityA = Identity::facility($orgA);
    $orgB = Identity::organization();
    $facilityB = Identity::facility($orgB);

    $chargeA = refundCharge($orgA, $facilityA, 5000);

    $clerkA = Identity::user();
    Identity::assign($clerkA, 'billing_clerk', $orgA, $facilityA);
    $this->withToken(Identity::tokenFor($clerkA))
        ->postJson('/api/v1/charges/'.$chargeA->getKey().'/refunds', [
            'amountMinor' => 1000,
            'reasonCode' => 'adjustment',
        ])
        ->assertCreated();

    $requestA = RefundRequest::query()->where('charge_id', $chargeA->getKey())->firstOrFail();

    // Tenant-B billing clerk attacking tenant A's charge and request.
    $clerkB = Identity::user();
    Identity::assign($clerkB, 'billing_clerk', $orgB, $facilityB);

    // Read of A's request list → 404 (existence hidden).
    $this->withToken(Identity::tokenFor($clerkB))
        ->getJson('/api/v1/charges/'.$chargeA->getKey().'/refunds')
        ->assertStatus(404);

    // Write against A's charge → 403.
    $this->withToken(Identity::tokenFor($clerkB))
        ->postJson('/api/v1/charges/'.$chargeA->getKey().'/refunds', [
            'amountMinor' => 500,
            'reasonCode' => 'adjustment',
        ])
        ->assertStatus(403);

    // Write against A's request → 403 (approver from B has no scope).
    $this->withToken(Identity::tokenFor($clerkB))
        ->postJson('/api/v1/refund-requests/'.$requestA->getKey().'/approve')
        ->assertStatus(403);

    // Tenant A's data is untouched.
    expect($requestA->refresh()->status)->toBe(RefundRequest::STATUS_REQUESTED)
        ->and($chargeA->refresh()->status)->toBe(Charge::STATUS_POSTED)
        ->and(RefundRequest::query()->where('charge_id', $chargeA->getKey())->count())->toBe(1);
});

it('enforces cross-facility isolation within a tenant', function () {
    $org = Identity::organization();
    $facilityA = Identity::facility($org);
    $facilityB = Identity::facility($org);

    $chargeA = refundCharge($org, $facilityA, 5000);

    $clerkA = Identity::user();
    Identity::assign($clerkA, 'billing_clerk', $org, $facilityA);
    $this->withToken(Identity::tokenFor($clerkA))
        ->postJson('/api/v1/charges/'.$chargeA->getKey().'/refunds', [
            'amountMinor' => 1000,
            'reasonCode' => 'adjustment',
        ])
        ->assertCreated();

    $requestA = RefundRequest::query()->where('charge_id', $chargeA->getKey())->firstOrFail();

    // Facility-B billing clerk cannot reach facility A's charge/request.
    $clerkB = Identity::user();
    Identity::assign($clerkB, 'billing_clerk', $org, $facilityB);

    $this->withToken(Identity::tokenFor($clerkB))
        ->getJson('/api/v1/charges/'.$chargeA->getKey().'/refunds')
        ->assertStatus(404);

    $this->withToken(Identity::tokenFor($clerkB))
        ->postJson('/api/v1/refund-requests/'.$requestA->getKey().'/approve')
        ->assertStatus(403);

    expect($requestA->refresh()->status)->toBe(RefundRequest::STATUS_REQUESTED);
});

it('keeps patient identifiers and reason text out of audit payloads', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();
    $approver = Identity::user();

    $charge = refundCharge($org, $facility, 10000);
    $patient = $charge->patient;
    $patientName = $patient->full_name;

    Identity::assign($clerk, 'billing_clerk', $org, $facility);
    Identity::assign($approver, 'org_admin', $org, $facility);

    $this->withToken(Identity::tokenFor($clerk))
        ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
            'amountMinor' => 3000,
            'reasonCode' => 'overcharge',
            'reasonNote' => 'Patient Mrs. '.$patientName.' was double-billed for the consultation.',
        ])
        ->assertCreated();

    $request = RefundRequest::query()->where('charge_id', $charge->getKey())->firstOrFail();

    $this->withToken(Identity::tokenFor($approver))
        ->postJson('/api/v1/refund-requests/'.$request->getKey().'/approve')
        ->assertOk();

    // No patient name and no free-text reason note in ANY audit payload.
    foreach (AuditEvent::query()->whereIn('action', ['refund.requested', 'refund.approved'])->get() as $event) {
        $encoded = json_encode($event->payload);
        expect($encoded)->not->toContain($patientName)
            ->and($encoded)->not->toContain('double-billed')
            ->and($encoded)->not->toContain('Mrs.');
    }

    // Facts are present: charge id, amount, structured reason code.
    $requested = AuditEvent::query()->where('action', 'refund.requested')->firstOrFail();
    expect($requested->payload)
        ->toHaveKey('chargeId', $charge->getKey())
        ->toHaveKey('amountMinor', 3000)
        ->toHaveKey('reasonCode', 'overcharge');
});

it('lists refund requests for a charge oldest first with billing:view', function () {
    $org = Identity::organization();
    $facility = Identity::facility($org);
    $clerk = Identity::user();

    $charge = refundCharge($org, $facility, 10000);

    Identity::assign($clerk, 'billing_clerk', $org, $facility);

    foreach ([2000, 3000] as $amount) {
        $this->withToken(Identity::tokenFor($clerk))
            ->postJson('/api/v1/charges/'.$charge->getKey().'/refunds', [
                'amountMinor' => $amount,
                'reasonCode' => 'adjustment',
            ])
            ->assertCreated();
    }

    $this->withToken(Identity::tokenFor($clerk))
        ->getJson('/api/v1/charges/'.$charge->getKey().'/refunds')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.amountMinor', 2000)
        ->assertJsonPath('data.1.amountMinor', 3000);

    // The receptionist has no billing:view — refused.
    $receptionist = Identity::user();
    Identity::assign($receptionist, 'receptionist', $org, $facility);
    $this->withToken(Identity::tokenFor($receptionist))
        ->getJson('/api/v1/charges/'.$charge->getKey().'/refunds')
        ->assertStatus(403);
});
