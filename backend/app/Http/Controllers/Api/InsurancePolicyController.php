<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Patient\StorePolicyRequest;
use App\Http\Requests\Patient\UpdatePolicyRequest;
use App\Models\InsurancePolicy;
use App\Models\Patient;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\PatientTimeline;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patient insurance policies (DATABASE.md §3.14): coverage under a payer,
 * used at charge time. One active policy per (patient, payer); policy
 * numbers unique per payer among active policies. Status is a lifecycle,
 * never a deletion.
 */
final class InsurancePolicyController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PatientTimeline $timeline,
    ) {}

    public function index(Request $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: false);

        $policies = $patient->insurancePolicies()
            ->with('payer:id,name,code')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (InsurancePolicy $policy): array => self::present($policy))
            ->values();

        return Envelope::success(data: $policies, request: $request);
    }

    public function store(StorePolicyRequest $request, Patient $patient): JsonResponse
    {
        AccessCheck::scoped($patient, write: true);

        $context = TenantContext::current();
        $payerId = (string) $request->validated('payerId');
        $policyNumber = (string) $request->validated('policyNumber');

        $duplicatePayer = $patient->insurancePolicies()
            ->where('payer_id', $payerId)
            ->where('status', 'active')
            ->exists();

        if ($duplicatePayer) {
            return Envelope::error(
                'RESOURCE_EXISTS',
                'This patient already has an active policy with this payer.',
                409,
                request: $request,
            );
        }

        $duplicateNumber = InsurancePolicy::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('payer_id', $payerId)
            ->whereRaw('lower(policy_number) = ?', [strtolower($policyNumber)])
            ->where('status', 'active')
            ->exists();

        if ($duplicateNumber) {
            return Envelope::error(
                'RESOURCE_EXISTS',
                'This policy number is already active for the payer.',
                409,
                request: $request,
            );
        }

        $policy = InsurancePolicy::query()->create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->getKey(),
            'payer_id' => $payerId,
            'policy_number' => $policyNumber,
            'coverage_type' => $request->validated('coverageType'),
            'valid_from' => $request->validated('validFrom'),
            'valid_to' => $request->validated('validTo'),
            'benefits' => $request->validated('benefits', []),
            'status' => InsurancePolicy::STATUS_ACTIVE,
            'lock_version' => 0,
            'created_by' => $context->user?->getKey(),
        ]);

        $this->audit->record(
            'patient.insurance.added',
            'insurance_policy',
            $policy->getKey(),
            ['patientId' => $patient->getKey(), 'payerId' => $payerId, 'validFrom' => $policy->valid_from?->toDateString(), 'validTo' => $policy->valid_to?->toDateString()],
            $request,
        );
        $this->timeline->record($patient, 'patient.insurance_added', ['payerId' => $payerId], $request);

        return Envelope::success(data: self::present($policy), status: 201, request: $request);
    }

    public function update(UpdatePolicyRequest $request, InsurancePolicy $policy): JsonResponse
    {
        AccessCheck::patientChild($policy, write: true);

        $clientVersion = (int) $request->validated('lockVersion');
        $changes = [];

        foreach (['coverage_type', 'valid_from', 'valid_to', 'benefits'] as $field) {
            $input = match ($field) {
                'coverage_type' => 'coverageType',
                'valid_from' => 'validFrom',
                'valid_to' => 'validTo',
                default => 'benefits',
            };

            if ($request->has($input)) {
                $changes[$field] = [$policy->getAttribute($field), $request->validated($input)];
                $policy->setAttribute($field, $request->validated($input));
            }
        }

        $context = TenantContext::current();
        $affected = DB::table('insurance_policies')
            ->where('id', $policy->getKey())
            ->where('lock_version', $clientVersion)
            ->update([
                'coverage_type' => $policy->coverage_type,
                'valid_from' => $policy->valid_from?->toDateString(),
                'valid_to' => $policy->valid_to?->toDateString(),
                'benefits' => $policy->benefits,
                'lock_version' => $policy->lock_version + 1,
                'updated_by' => $context->user?->getKey(),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            return Envelope::error('LOCK_CONFLICT', 'This policy was changed by someone else. Reload and retry.', 409, request: $request);
        }

        $policy->lock_version += 1;

        $this->audit->record('patient.insurance.updated', 'insurance_policy', $policy->getKey(), ['changes' => $changes], $request);
        $this->timeline->record($policy->patient, 'patient.insurance_updated', ['policyNumber' => $policy->policy_number], $request);

        return Envelope::success(data: self::present($policy), request: $request);
    }

    public function cancel(Request $request, InsurancePolicy $policy): JsonResponse
    {
        AccessCheck::patientChild($policy, write: true);

        if ($policy->status !== InsurancePolicy::STATUS_ACTIVE) {
            return Envelope::error('CONFLICT', 'Only an active policy can be cancelled.', 409, request: $request);
        }

        $reason = (string) $request->input('reason', '');
        $policy->status = InsurancePolicy::STATUS_CANCELLED;
        $policy->updated_by = TenantContext::current()->user?->getKey();
        $policy->save();

        $this->audit->record(
            'patient.insurance.cancelled',
            'insurance_policy',
            $policy->getKey(),
            ['patientId' => $policy->patient_id, 'reason' => $reason],
            $request,
        );
        $this->timeline->record($policy->patient, 'patient.insurance_cancelled', ['reason' => $reason], $request);

        return Envelope::success(data: self::present($policy), request: $request);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(InsurancePolicy $policy): array
    {
        return [
            'id' => $policy->getKey(),
            'patientId' => $policy->patient_id,
            'payerId' => $policy->payer_id,
            'payer' => $policy->payer ? ['id' => $policy->payer->getKey(), 'name' => $policy->payer->name, 'code' => $policy->payer->code] : null,
            'policyNumber' => $policy->policy_number,
            'coverageType' => $policy->coverage_type,
            'validFrom' => $policy->valid_from?->toDateString(),
            'validTo' => $policy->valid_to?->toDateString(),
            'benefits' => $policy->benefits,
            'status' => $policy->status,
            'lockVersion' => $policy->lock_version,
        ];
    }
}
