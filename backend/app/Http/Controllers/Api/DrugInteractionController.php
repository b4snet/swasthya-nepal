<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\DrugInteraction;
use App\Models\Medication;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Drug interaction clinical decision support (PHASE 29).
 *
 * Provides:
 *   POST /drug-interactions/check — check a list of medication IDs for interactions
 *   GET  /drug-interactions        — list interaction rules for the tenant
 *   POST /drug-interactions        — create a new interaction rule
 *
 * The check endpoint is the primary clinical safety interface. It accepts
 * an array of medication IDs and returns all known interactions between
 * any pair, grouped by severity. The frontend uses this to display
 * warnings BEFORE a prescription is finalized.
 */
final class DrugInteractionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /drug-interactions/check — check medication list for interactions.
     *
     * Accepts: { medicationIds: string[] }
     * Returns: { interactions: [...], hasCritical: boolean, hasMajor: boolean }
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'medicationIds' => 'required|array|min:2',
            'medicationIds.*' => 'required|string',
        ]);

        $context = TenantContext::current();
        $medicationIds = $request->input('medicationIds');

        // Verify all medications exist and are in-scope
        $medications = Medication::query()
            ->whereIn('id', $medicationIds)
            ->get();

        if ($medications->count() !== count($medicationIds)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'One or more medication IDs are invalid.', 422);
        }

        // Find all interactions between any pair in the list
        $interactions = DrugInteraction::query()
            ->where('tenant_id', $context->tenantId())
            ->where('is_active', true)
            ->where(function ($query) use ($medicationIds) {
                // Check both directions: A→B and B→A
                foreach ($medicationIds as $i => $a) {
                    foreach ($medicationIds as $j => $b) {
                        if ($i >= $j) {
                            continue;
                        } // avoid duplicates and self
                        $query->orWhere(function ($q) use ($a, $b) {
                            $q->where('medication_a_id', $a)
                                ->where('medication_b_id', $b);
                        });
                        $query->orWhere(function ($q) use ($a, $b) {
                            $q->where('medication_a_id', $b)
                                ->where('medication_b_id', $a);
                        });
                    }
                }
            })
            ->with(['medicationA:id,generic_name,brand_name', 'medicationB:id,generic_name,brand_name'])
            ->get()
            ->unique('id'); // deduplicate bidirectional matches

        $hasCritical = $interactions->contains('severity', DrugInteraction::SEVERITY_CRITICAL);
        $hasMajor = $interactions->contains('severity', DrugInteraction::SEVERITY_MAJOR);

        return Envelope::success(data: [
            'interactions' => $interactions->map(fn (DrugInteraction $i) => [
                'id' => $i->getKey(),
                'severity' => $i->severity,
                'medicationA' => [
                    'id' => $i->medication_a_id,
                    'name' => $i->medicationA->generic_name ?? $i->medicationA->brand_name ?? 'Unknown',
                ],
                'medicationB' => [
                    'id' => $i->medication_b_id,
                    'name' => $i->medicationB->generic_name ?? $i->medicationB->brand_name ?? 'Unknown',
                ],
                'description' => $i->description,
                'clinicalEffect' => $i->clinical_effect,
                'recommendation' => $i->recommendation,
            ])->values(),
            'hasCritical' => $hasCritical,
            'hasMajor' => $hasMajor,
            'count' => $interactions->count(),
        ], request: $request);
    }

    /**
     * GET /drug-interactions — list interaction rules for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $interactions = DrugInteraction::query()
            ->where('tenant_id', $context->tenantId())
            ->with(['medicationA:id,generic_name,brand_name', 'medicationB:id,generic_name,brand_name'])
            ->orderBy('severity')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn (DrugInteraction $i) => [
                'id' => $i->getKey(),
                'medicationA' => ['id' => $i->medication_a_id, 'name' => $i->medicationA->generic_name ?? 'Unknown'],
                'medicationB' => ['id' => $i->medication_b_id, 'name' => $i->medicationB->generic_name ?? 'Unknown'],
                'severity' => $i->severity,
                'description' => $i->description,
                'clinicalEffect' => $i->clinical_effect,
                'recommendation' => $i->recommendation,
                'isActive' => $i->is_active,
                'createdAt' => $i->created_at?->toIso8601String(),
            ]);

        return Envelope::success(data: $interactions, request: $request);
    }

    /**
     * POST /drug-interactions — create a new interaction rule.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'medicationAId' => 'required|string|exists:medications,id',
            'medicationBId' => 'required|string|exists:medications,id|different:medicationAId',
            'severity' => 'required|in:critical,major,moderate',
            'description' => 'required|string|max:500',
            'clinicalEffect' => 'nullable|string|max:500',
            'recommendation' => 'nullable|string|max:500',
        ]);

        $context = TenantContext::current();

        // Ensure canonical ordering (A < B) to prevent duplicates
        $a = $request->input('medicationAId');
        $b = $request->input('medicationBId');
        if ($a > $b) {
            [$a, $b] = [$b, $a];
        }

        // Check for existing interaction
        $existing = DrugInteraction::query()
            ->where('tenant_id', $context->tenantId())
            ->where('medication_a_id', $a)
            ->where('medication_b_id', $b)
            ->first();

        if ($existing) {
            throw new ApiException(ErrorCodes::CONFLICT, 'An interaction rule already exists for this medication pair.', 409);
        }

        $interaction = DrugInteraction::query()->create([
            'tenant_id' => $context->tenantId(),
            'medication_a_id' => $a,
            'medication_b_id' => $b,
            'severity' => $request->input('severity'),
            'description' => $request->input('description'),
            'clinical_effect' => $request->input('clinicalEffect'),
            'recommendation' => $request->input('recommendation'),
            'is_active' => true,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'drug_interaction.created',
            'drug_interaction',
            $interaction->getKey(),
            ['severity' => $interaction->severity, 'medicationAId' => $a, 'medicationBId' => $b],
            $request,
        );

        return Envelope::success(data: [
            'id' => $interaction->getKey(),
            'severity' => $interaction->severity,
            'description' => $interaction->description,
        ], request: $request);
    }
}
