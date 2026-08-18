<?php

namespace App\Http\Requests\Admission;

use App\Http\Requests\ApiRequest;

/**
 * POST admissions/{admission}/transfer — the audited bed-to-bed/ward-to-ward
 * move (ROADMAP Phase 8, PRODUCT_REQUIREMENTS §6.5): the target bed id plus
 * the transfer reason ("transfers audited with reasons"). The reason is
 * clinical context — stored in the immutable transfer_event, never in audit
 * payloads.
 *
 * High-risk gate (CLINICAL_SAFETY.md §16 — "Discharge / transfer a patient |
 * Identity confirmation"): identity re-confirmation (name + MRN on-screen)
 * is REQUIRED — an incomplete transfer (identityConfirmed missing or false)
 * is rejected at the request layer with no side effects.
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
            // CLINICAL_SAFETY §16 — identity confirmation is a hard gate for
            // the transfer action (accepted = the on-screen name + MRN dialog
            // was confirmed in-flow, never at session start).
            'identityConfirmed' => ['required', 'boolean', 'accepted'],
        ];
    }
}
