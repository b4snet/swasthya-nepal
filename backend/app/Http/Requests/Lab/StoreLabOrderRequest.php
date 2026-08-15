<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/lab-orders — the provider orders one or more
 * catalog tests in one atomic call. testIds must be distinct; each is
 * validated as an active, in-scope catalog test in the controller.
 */
class StoreLabOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'testIds' => ['required', 'array', 'min:1', 'max:20'],
            'testIds.*' => ['required', 'uuid', 'distinct'],
            'priority' => ['nullable', 'string', 'in:routine,urgent,stat'],
            'clinicalIndication' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
