<?php

namespace App\Http\Requests\Cdss;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/cdss/rules — store a NEW VERSION of a knowledge-base rule.
 * Rules are never edited in place; a change is a new draft version that
 * must be explicitly activated (which supersedes the prior active version).
 */
class StoreCdssRuleRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ruleType' => ['required', 'in:interaction,allergen,dose,pathway'],
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:200'],
            'severity' => ['nullable', 'in:contraindicated,major,moderate,minor'],
            'spec' => ['required', 'array'],
        ];
    }
}
