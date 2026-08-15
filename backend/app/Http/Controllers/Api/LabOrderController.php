<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lab\EnterLabResultsRequest;
use App\Http\Requests\Lab\StoreLabOrderRequest;
use App\Models\CriticalValueEvent;
use App\Models\Encounter;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Staff;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The laboratory/radiology order lifecycle (DATABASE.md §3.26,
 * PRODUCT_REQUIREMENTS §6.8):
 *
 *   doctor (encounter provider) orders → sample collected →
 *   processing → results entered (lab:result_entry) →
 *   verified by a DIFFERENT staff member (lab:verify) →
 *   final report released (lab:report) — immutable afterwards
 *
 * Every transition is a compare-and-swap on (status, lock_version), so two
 * concurrent actors can never double-advance the order: the loser gets 409
 * CONFLICT. Entry ≠ verification is enforced twice — distinct permissions
 * AND a different-staff guard.
 */
final class LabOrderController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /encounters/{encounter}/lab-orders — the provider orders tests.
     */
    public function store(StoreLabOrderRequest $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: true);

        if (! in_array($encounter->status, [Encounter::STATUS_OPEN, Encounter::STATUS_IN_PROGRESS], true)) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Investigations can only be ordered on an open encounter (current status: '.$encounter->status.').',
                409,
            );
        }

        $context = TenantContext::current();
        $provider = $this->currentProvider($encounter, $context);

        $testIds = $request->validated('testIds');
        $tests = LabTest::query()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('facility_id', $encounter->facility_id)
            ->whereIn('id', $testIds)
            ->where('status', LabTest::STATUS_ACTIVE)
            ->get();

        if ($tests->count() !== count($testIds)) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'Every ordered test must be an active catalog item in scope.',
                422,
            );
        }

        $order = DB::transaction(function () use ($request, $encounter, $context, $provider, $tests): LabOrder {
            $order = LabOrder::query()->create([
                'tenant_id' => $encounter->tenant_id,
                'facility_id' => $encounter->facility_id,
                'patient_id' => $encounter->patient_id,
                'encounter_id' => $encounter->getKey(),
                'ordered_by_staff_id' => $provider->getKey(),
                'priority' => $request->validated('priority', LabOrder::PRIORITY_ROUTINE),
                'status' => LabOrder::STATUS_ORDERED,
                'clinical_indication' => $request->validated('clinicalIndication'),
                'ordered_at' => now(),
                'lock_version' => 0,
                'created_by' => $context->user?->getKey(),
            ]);

            foreach ($tests as $test) {
                LabOrderItem::query()->create([
                    'tenant_id' => $order->tenant_id,
                    'facility_id' => $order->facility_id,
                    'lab_order_id' => $order->getKey(),
                    'lab_test_id' => $test->getKey(),
                    'reference_range' => $test->reference_range, // frozen at order time
                    'created_by' => $context->user?->getKey(),
                ]);
            }

            return $order;
        });

        $this->audit->record(
            'lab_order.created',
            'lab_order',
            $order->getKey(),
            ['patientId' => $order->patient_id, 'encounterId' => $order->encounter_id, 'orderedByStaffId' => $order->ordered_by_staff_id, 'testCount' => $order->items()->count(), 'priority' => $order->priority],
            $request,
        );

        return Envelope::success(data: $this->present($order), status: 201, request: $request);
    }

    /**
     * GET /encounters/{encounter}/lab-orders — the care team's view: all
     * orders for the visit with their current status and items.
     */
    public function forEncounter(Request $request, Encounter $encounter): JsonResponse
    {
        AccessCheck::scoped($encounter, write: false);

        $orders = $encounter->labOrders()
            ->with('items.test:id,name,sample_type')
            ->orderBy('ordered_at')
            ->get()
            ->map(fn (LabOrder $order): array => $this->present($order))
            ->values();

        return Envelope::success(data: $orders, request: $request);
    }

    /**
     * GET /patients/{patient}/lab-orders — released results for one patient.
     * Only verified/reported orders are exposed (results are released by
     * verification, never before); the patient scope is the bound record, so
     * another patient's results are unreachable through this surface.
     */
    public function forPatient(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $orders = LabOrder::query()
            ->where('patient_id', $patient->getKey())
            ->whereIn('status', [LabOrder::STATUS_VERIFIED, LabOrder::STATUS_REPORTED])
            ->with('items.test:id,name,sample_type')
            ->orderBy('verified_at')
            ->get()
            ->map(fn (LabOrder $order): array => $this->present($order))
            ->values();

        return Envelope::success(data: $orders, request: $request);
    }

    /**
     * GET /lab-orders/{labOrder} — full order detail.
     */
    public function show(Request $request, LabOrder $labOrder): JsonResponse
    {
        AccessCheck::scoped($labOrder, write: false);

        $labOrder->load('items.test:id,name,sample_type');

        return Envelope::success(data: $this->present($labOrder), request: $request);
    }

    /**
     * POST /lab-orders/{labOrder}/collect — ordered → collected.
     */
    public function collect(Request $request, LabOrder $labOrder): JsonResponse
    {
        AccessCheck::scoped($labOrder, write: true);
        $this->guardStatus($labOrder, LabOrder::STATUS_ORDERED, 'collected');

        $context = TenantContext::current();
        $staff = $this->currentLabStaff($labOrder, $context);

        return $this->transition($request, $labOrder, [
            'status' => LabOrder::STATUS_COLLECTED,
            'collected_by_staff_id' => $staff->getKey(),
            'collected_at' => now(),
        ], 'lab_order.collected');
    }

    /**
     * POST /lab-orders/{labOrder}/process — collected → processing.
     */
    public function process(Request $request, LabOrder $labOrder): JsonResponse
    {
        AccessCheck::scoped($labOrder, write: true);
        $this->guardStatus($labOrder, LabOrder::STATUS_COLLECTED, 'processed');

        $context = TenantContext::current();
        $this->currentLabStaff($labOrder, $context);

        return $this->transition($request, $labOrder, [
            'status' => LabOrder::STATUS_PROCESSING,
            'processing_at' => now(),
        ], 'lab_order.processing');
    }

    /**
     * POST /lab-orders/{labOrder}/results — processing → results_entered.
     * Every ordered test must receive a result in one atomic call.
     */
    public function enterResults(EnterLabResultsRequest $request, LabOrder $labOrder): JsonResponse
    {
        AccessCheck::scoped($labOrder, write: true);
        $this->guardStatus($labOrder, LabOrder::STATUS_PROCESSING, 'edited with results');

        $context = TenantContext::current();
        $staff = $this->currentLabStaff($labOrder, $context);

        $items = $labOrder->items()->get();
        $submitted = collect($request->validated('results'));

        if ($submitted->count() !== $items->count() || $submitted->pluck('itemId')->diff($items->pluck('id'))->isNotEmpty()) {
            throw new ApiException(
                ErrorCodes::VALIDATION_ERROR,
                'Results must be entered once for every ordered test, and only for tests on this order.',
                422,
            );
        }

        $byItem = $submitted->keyBy('itemId');

        // Phase 3 slice 7 — items flagged critical trigger a
        // critical_value_event targeted at the ordering clinician. The event
        // is created in the SAME transaction as the entry (a critical value
        // can never be entered without its escalation record), and the
        // entry transition is CAS — entry happens exactly once per order, so
        // a repeated trigger is structurally impossible (the partial unique
        // uq_critical_value_events_tenant_item_open is the DB backstop).
        $triggeredItemIds = [];

        DB::transaction(function () use ($labOrder, $context, $staff, $items, $byItem, &$triggeredItemIds): void {
            foreach ($items as $item) {
                $entry = $byItem[$item->getKey()];
                $item->update([
                    'result_value' => $entry['resultValue'],
                    'result_unit' => $entry['resultUnit'] ?? null,
                    'entered_by_staff_id' => $staff->getKey(),
                    'entered_at' => now(),
                    'updated_by' => $context->user?->getKey(),
                ]);

                if (($entry['isCritical'] ?? false) === true) {
                    CriticalValueEvent::query()->create([
                        'tenant_id' => $labOrder->tenant_id,
                        'facility_id' => $labOrder->facility_id,
                        'lab_order_item_id' => $item->getKey(),
                        'patient_id' => $labOrder->patient_id,
                        'encounter_id' => $labOrder->encounter_id,
                        'target_staff_id' => $labOrder->ordered_by_staff_id,
                        'status' => CriticalValueEvent::STATUS_TRIGGERED,
                        'detected_by_staff_id' => $staff->getKey(),
                        'detected_at' => now(),
                        'lock_version' => 0,
                        'created_by' => $context->user?->getKey(),
                    ]);
                    $triggeredItemIds[] = $item->getKey();
                }
            }

            $this->applyTransition($labOrder, LabOrder::STATUS_PROCESSING, [
                'status' => LabOrder::STATUS_RESULTS_ENTERED,
            ], $context);
        });

        $this->audit->record(
            'lab_order.results_entered',
            'lab_order',
            $labOrder->getKey(),
            ['encounterId' => $labOrder->encounter_id, 'enteredByStaffId' => $staff->getKey(), 'itemCount' => $items->count()],
            $request,
        );

        foreach ($triggeredItemIds as $itemId) {
            $this->audit->record(
                'critical_value.triggered',
                'critical_value_event',
                CriticalValueEvent::query()->where('lab_order_item_id', $itemId)->firstOrFail()->getKey(),
                ['encounterId' => $labOrder->encounter_id, 'itemId' => $itemId, 'targetStaffId' => $labOrder->ordered_by_staff_id],
                $request,
            );
        }

        return Envelope::success(data: $this->present($labOrder->fresh(['items.test:id,name,sample_type'])), request: $request);
    }

    /**
     * POST /lab-orders/{labOrder}/verify — results_entered → verified.
     * The verifier must hold lab:verify (route gate) AND must be a different
     * staff member than whoever entered the results (entry ≠ verification).
     */
    public function verify(Request $request, LabOrder $labOrder): JsonResponse
    {
        AccessCheck::scoped($labOrder, write: true);
        $this->guardStatus($labOrder, LabOrder::STATUS_RESULTS_ENTERED, 'verified');

        $context = TenantContext::current();
        $verifier = $this->currentLabStaff($labOrder, $context);

        $items = $labOrder->items()->get();

        if ($items->whereNull('result_value')->isNotEmpty()) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Every ordered test must have a result before the order can be verified.',
                409,
            );
        }

        if ($items->pluck('entered_by_staff_id')->contains($verifier->getKey())) {
            throw new ApiException(
                ErrorCodes::SCOPE_DENIED,
                'Verification must be performed by a different staff member than the one who entered the results.',
                403,
            );
        }

        DB::transaction(function () use ($labOrder, $context, $verifier, $items): void {
            foreach ($items as $item) {
                $item->update([
                    'verified_by_staff_id' => $verifier->getKey(),
                    'verified_at' => now(),
                ]);
            }

            $this->applyTransition($labOrder, LabOrder::STATUS_RESULTS_ENTERED, [
                'status' => LabOrder::STATUS_VERIFIED,
                'verified_by_staff_id' => $verifier->getKey(),
                'verified_at' => now(),
            ], $context);
        });

        $this->audit->record(
            'lab_order.verified',
            'lab_order',
            $labOrder->getKey(),
            ['encounterId' => $labOrder->encounter_id, 'verifiedByStaffId' => $verifier->getKey(), 'itemCount' => $items->count()],
            $request,
        );

        return Envelope::success(data: $this->present($labOrder->fresh(['items.test:id,name,sample_type'])), request: $request);
    }

    /**
     * POST /lab-orders/{labOrder}/report — verified → reported. The final
     * report is released; the order is immutable afterwards (corrections are
     * new audited versions — later phase).
     */
    public function report(Request $request, LabOrder $labOrder): JsonResponse
    {
        AccessCheck::scoped($labOrder, write: true);
        $this->guardStatus($labOrder, LabOrder::STATUS_VERIFIED, 'reported');

        $context = TenantContext::current();
        $reporter = $this->currentLabStaff($labOrder, $context);

        return $this->transition($request, $labOrder, [
            'status' => LabOrder::STATUS_REPORTED,
            'reported_by_staff_id' => $reporter->getKey(),
            'reported_at' => now(),
        ], 'lab_order.reported');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The encounter provider (the ordering clinician): the authenticated
     * user's staff profile, which must be the encounter's provider.
     */
    private function currentProvider(Encounter $encounter, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $encounter->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null || $staff->getKey() !== $encounter->provider_staff_id) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'Only the encounter provider can order investigations for this visit.', 403);
        }

        return $staff;
    }

    /**
     * The authenticated user's staff profile in the order's tenant.
     */
    private function currentLabStaff(LabOrder $labOrder, TenantContext $context): Staff
    {
        $staff = $context->user?->staff()
            ->where('tenant_id', $labOrder->tenant_id)
            ->where('status', '!=', Staff::STATUS_DEPARTED)
            ->first();

        if ($staff === null) {
            throw new ApiException(ErrorCodes::SCOPE_DENIED, 'No active staff profile for this user in the order\'s tenant.', 403);
        }

        return $staff;
    }

    private function guardStatus(LabOrder $labOrder, string $expected, string $gerund): void
    {
        if ($labOrder->status !== $expected) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'This lab order cannot be '.$gerund.' from its current state ('.$labOrder->status.').',
                409,
            );
        }
    }

    /**
     * Single-writer transitions (collect, process, report): status guard +
     * atomic CAS + audit + response.
     *
     * @param  array<string, mixed>  $fields
     */
    private function transition(Request $request, LabOrder $labOrder, array $fields, string $action): JsonResponse
    {
        $context = TenantContext::current();

        DB::transaction(function () use ($labOrder, $fields, $context): void {
            $this->applyTransition($labOrder, null, $fields, $context);
        });

        $this->audit->record(
            $action,
            'lab_order',
            $labOrder->getKey(),
            ['encounterId' => $labOrder->encounter_id, 'facilityId' => $labOrder->facility_id],
            $request,
        );

        return Envelope::success(data: $this->present($labOrder->fresh(['items.test:id,name,sample_type'])), request: $request);
    }

    /**
     * The atomic state transition: compare-and-swap on (status,
     * lock_version). A concurrent writer wins → the CAS affects 0 rows →
     * ApiException CONFLICT; the calling transaction rolls back, so no
     * half-updated order is ever observable.
     *
     * @param  array<string, mixed>  $fields
     */
    private function applyTransition(LabOrder $labOrder, ?string $expectedStatus, array $fields, TenantContext $context): void
    {
        $query = DB::table('lab_orders')
            ->where('id', $labOrder->getKey())
            ->where('lock_version', $labOrder->lock_version);

        if ($expectedStatus !== null) {
            $query->where('status', $expectedStatus);
        }

        $updated = $query->update(array_merge($fields, [
            'lock_version' => $labOrder->lock_version + 1,
            'updated_by' => $context->user?->getKey(),
        ]));

        if ($updated !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'This lab order was concurrently modified; refresh and retry.', 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(LabOrder $order): array
    {
        return [
            'id' => $order->getKey(),
            'facilityId' => $order->facility_id,
            'patientId' => $order->patient_id,
            'encounterId' => $order->encounter_id,
            'orderedByStaffId' => $order->ordered_by_staff_id,
            'priority' => $order->priority,
            'status' => $order->status,
            'clinicalIndication' => $order->clinical_indication,
            'orderedAt' => $order->ordered_at?->toIso8601String(),
            'collectedAt' => $order->collected_at?->toIso8601String(),
            'collectedByStaffId' => $order->collected_by_staff_id,
            'processingAt' => $order->processing_at?->toIso8601String(),
            'verifiedAt' => $order->verified_at?->toIso8601String(),
            'verifiedByStaffId' => $order->verified_by_staff_id,
            'reportedAt' => $order->reported_at?->toIso8601String(),
            'reportedByStaffId' => $order->reported_by_staff_id,
            'lockVersion' => $order->lock_version,
            'items' => $order->items->map(fn (LabOrderItem $item): array => [
                'id' => $item->getKey(),
                'testId' => $item->lab_test_id,
                'testName' => $item->test?->name,
                'sampleType' => $item->test?->sample_type,
                'resultValue' => $item->result_value,
                'resultUnit' => $item->result_unit,
                'referenceRange' => $item->reference_range,
                'enteredAt' => $item->entered_at?->toIso8601String(),
                'enteredByStaffId' => $item->entered_by_staff_id,
                'verifiedAt' => $item->verified_at?->toIso8601String(),
                'verifiedByStaffId' => $item->verified_by_staff_id,
            ])->values(),
        ];
    }
}
