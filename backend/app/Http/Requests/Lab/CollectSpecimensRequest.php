<?php

namespace App\Http\Requests\Lab;

use App\Http\Requests\ApiRequest;

/**
 * POST lab-orders/{labOrder}/specimens — collect one or more physical
 * samples for the order (Phase 3 slice 15, PRODUCT_REQUIREMENTS §6.8).
 * Each specimen is minted a UNIQUE per-tenant accession number at
 * collection; the order advances ordered → collected in the same atomic
 * step. specimenType is the only required clinical fact (the sample label);
 * the container is optional and non-clinical.
 */
class CollectSpecimensRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'specimens' => ['required', 'array', 'min:1', 'max:50'],
            'specimens.*.specimenType' => ['required', 'string', 'max:50'],
            'specimens.*.container' => ['nullable', 'string', 'max:50'],
        ];
    }
}
