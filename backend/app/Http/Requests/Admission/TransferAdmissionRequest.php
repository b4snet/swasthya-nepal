<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\ApiRequest;

/**
 * POST admissions/{admission}/transfer — the audited bed-to-bed/ward-to-ward
 * move (ROADMAP Phase 8, PRODUCT_REQUIREMENTS §6.5): the target bed id plus
 * the transfer reason ("transfers audited with reasons"). The reason is
 * clinical context — stored in the immutable transfer_event, never in audit
 * payloads.
 */
class TransferAdmissionRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'toBedId' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
