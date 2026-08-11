<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;
use App\Models\Payer;
use App\Support\TenantContext;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/patients/{patient}/insurance-policies.
 *
 * The payer must belong to the caller's tenant (composite FK enforces it
 * structurally; this pre-check returns a clean 422). One active policy per
 * (patient, payer) and per (payer, policy_number) — enforced and surfaced
 * as 409 by the controller.
 */
class StorePolicyRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payerId' => [
                'required',
                'uuid',
                function (string $attribute, mixed $value, callable $fail): void {
                    $context = TenantContext::current();
                    $payer = Payer::query()->where('id', $value)->first();

                    if ($payer === null || $payer->tenant_id !== $context->tenantId()) {
                        $fail('The payer does not exist in this organization.');
                    }
                },
            ],
            'policyNumber' => ['required', 'string', 'max:100'],
            'coverageType' => ['required', 'string', 'max:100'],
            'validFrom' => ['required', 'date'],
            'validTo' => ['nullable', 'date', 'after_or_equal:validFrom'],
            'benefits' => ['nullable', 'array'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if ($this->has('validTo') && $this->input('validTo') !== null) {
                // A policy expiring before it starts is meaningless — caught
                // by after_or_equal; an already-expired policy cannot be
                // attached as active.
                if ($this->input('validTo') !== null && strtotime((string) $this->input('validTo')) < strtotime((string) $this->input('validFrom'))) {
                    $validator->errors()->add('validTo', 'validTo must not be before validFrom.');
                }
            }
        });
    }
}
