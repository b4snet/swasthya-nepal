<?php

namespace App\Http\Requests\Ai;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/ai/drafts — create a grounded assistive draft (Tier 2).
 * The draft is tied to the record refs it is grounded in (provenance) and
 * pinned to the producing model/version; it reaches a record ONLY after
 * clinician sign-off. When inference is unavailable the request degrades
 * loudly (503) — the manual path stays fully available (AI_RULES.md §17).
 */
class CreateAiDraftRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patientId' => ['required', 'uuid'],
            'encounterId' => ['nullable', 'uuid'],
            'function' => ['required', 'in:documentation_draft,summarization,forecast'],
            'context' => ['required', 'array'],
            'context.*' => ['nullable', 'string'],
            'sourceRefs' => ['nullable', 'array'],
            'sourceRefs.*' => ['array'],
            'sourceRefs.*.type' => ['required_with:sourceRefs', 'string', 'max:40'],
            'sourceRefs.*.id' => ['required_with:sourceRefs', 'string', 'max:80'],
        ];
    }
}
