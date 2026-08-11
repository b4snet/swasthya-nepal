<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Base request for every Swasthya API endpoint (API_CONTRACTS.md §6,
 * MASTER_RULES.md §12.5).
 *
 *  - all input is validated server-side with explicit rules;
 *  - unknown fields are rejected (strict mode) — a typo is a 422, never a
 *    silent ignore;
 *  - authorization is enforced by policies (MASTER_RULES.md §8) — concrete
 *    requests override authorize() where a policy check applies.
 */
abstract class ApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowed = $this->knownFields();

            foreach (array_keys($this->all()) as $field) {
                if (! in_array($field, $allowed, true)) {
                    $validator->errors()->add((string) $field, sprintf('Field "%s" is not allowed.', $field));
                }
            }
        });
    }

    /**
     * Top-level field names accepted by this request (derived from rules()).
     *
     * @return list<string>
     */
    private function knownFields(): array
    {
        $fields = [];

        foreach (array_keys($this->rules()) as $key) {
            $fields[] = Str::before($key, '.');
        }

        return array_values(array_unique($fields));
    }
}
