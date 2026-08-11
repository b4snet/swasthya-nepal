<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Validator;

/**
 * POST /api/v1/patients/{patient}/contacts.
 *
 * Exactly one of `value` (phone/email/emergency phone) or `address` (object)
 * is required. Emergency contacts additionally carry contactPerson
 * (name, relation). One active primary per (patient, type).
 */
class StoreContactRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:phone,email,address,emergency_contact'],
            'value' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'array'],
            'contactPerson' => ['nullable', 'array'],
            'contactPerson.name' => ['required_with:contactPerson', 'string', 'min:2', 'max:255'],
            'contactPerson.relation' => ['required_with:contactPerson', 'string', 'max:100'],
            'isPrimary' => ['sometimes', 'boolean'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $validator->after(function (Validator $validator): void {
            $type = $this->input('type');
            $hasValue = $this->input('value') !== null;
            $hasAddress = $this->input('address') !== null;

            if ($hasValue === $hasAddress) {
                $validator->errors()->add('value', 'Provide exactly one of value (phone/email) or address.');
            }

            if ($type === 'emergency_contact' && $this->input('contactPerson.name') === null) {
                $validator->errors()->add('contactPerson', 'An emergency contact requires a name.');
            }
        });
    }
}
