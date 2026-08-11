<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;
use App\Models\Payer;
use App\Support\TenantContext;

/**
 * POST /api/v1/organizations/{organization}/payers — add to the tenant's
 * payers master. Code is unique per tenant.
 */
class StorePayerRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $context = TenantContext::current();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($context): void {
                    $exists = Payer::query()
                        ->where('tenant_id', $context->tenantId())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A payer with this code already exists in this organization.');
                    }
                },
            ],
            'payerType' => ['required', 'in:government,private,tpa,other'],
        ];
    }
}
