<?php

namespace App\Http\Requests\Assets;

use App\Http\Requests\ApiRequest;

/**
 * POST work-orders/{order}/complete — close maintenance work. Downtime must
 * be closed when the order tracked it; a certification reference makes the
 * maintenance provable.
 */
class CompleteWorkOrderRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'downtimeEndedAt' => ['nullable', 'date', 'after:downtimeStartedAt'],
            'certificationRef' => ['nullable', 'string', 'max:255'],
        ];
    }
}
