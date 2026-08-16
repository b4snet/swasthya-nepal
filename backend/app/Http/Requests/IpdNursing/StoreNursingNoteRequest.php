<?php

namespace App\Http\Requests\IpdNursing;

use App\Http\Requests\ApiRequest;

/**
 * POST admissions/{admission}/nursing-notes — a draft nursing note with
 * structured content sections (observation/intervention/response/plan/other
 * are free keys; content is clinical PHI and never leaves the clinical
 * record).
 */
class StoreNursingNoteRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'array', 'max:10'],
            'content.*' => ['string', 'max:5000'],
        ];
    }
}
