<?php

namespace App\Http\Requests\FacilitySettings;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/**
 * PUT /api/v1/facilities/{facility}/settings.
 *
 * Bulk upsert: `settings` is a flat map of key → value (jsonb). Keys are
 * namespaced identifiers; values may be any JSON-serializable scalar, list,
 * or object. Every change bumps the setting's version and is audited with
 * old and new values (PRODUCT_REQUIREMENTS §5.5).
 */
class UpdateFacilitySettingsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'max:50'],
            'settings.*' => ['required'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            foreach (array_keys($this->input('settings', [])) as $key) {
                if (! is_string($key) || preg_match('/^[a-z][a-z0-9._-]{1,99}$/', $key) !== 1) {
                    $validator->errors()->add('settings', sprintf('Setting key "%s" is not a valid identifier.', (string) $key));
                }
            }
        });
    }
}
