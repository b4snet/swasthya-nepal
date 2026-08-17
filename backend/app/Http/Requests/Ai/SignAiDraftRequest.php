<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/ai/drafts/{aiDraft}/sign — the clinician's review act.
 * `action` is `sign` (the draft may enter the record — the accountable
 * human act, AI_RULES.md §9) or `withdraw` (the draft is rejected and
 * never reaches the record). Review is real: the signer identity is
 * recorded either way.
 */
class SignAiDraftRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:sign,withdraw'],
        ];
    }
}
