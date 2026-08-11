<?php

namespace App\Http\Requests\Encounter;

use App\Http\Requests\ApiRequest;

/**
 * POST encounters/{encounter}/notes — structured clinical documentation.
 * content is a JSON object of sections (complaint, history, examination,
 * assessment, plan) — never free-form blob, always structured.
 */
class StoreClinicalNoteRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'noteType' => ['nullable', 'in:consultation,nursing,procedure,progress,discharge,other'],
            'content' => ['required', 'array', 'min:1'],
            'content.*' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
