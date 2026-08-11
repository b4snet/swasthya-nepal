<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/v1/contacts/{contact} — update the contact detail, primary
 * flag, or retire it (status superseded). Never deleted: history matters
 * for care continuity (DATABASE.md §3.13).
 */
class UpdateContactRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'array'],
            'contactPerson' => ['nullable', 'array'],
            'contactPerson.name' => ['required_with:contactPerson', 'string', 'min:2', 'max:255'],
            'contactPerson.relation' => ['required_with:contactPerson', 'string', 'max:100'],
            'isPrimary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,superseded'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            if (! $this->has('status') && ! $this->has('value') && ! $this->has('address') && ! $this->has('isPrimary') && ! $this->has('contactPerson')) {
                $validator->errors()->add('_', 'Nothing to update.');
            }
        });
    }
}
