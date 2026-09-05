<?php

namespace App\Http\Requests\Patient;

use App\Http\Requests\ApiRequest;
use App\Models\PatientContact;
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
            if ($this->has('status') || $this->has('value') || $this->has('address') || $this->has('isPrimary') || $this->has('contactPerson')) {
                // Fall through to the invariants below.
            } else {
                $validator->errors()->add('_', 'Nothing to update.');

                return;
            }

            /** @var PatientContact|null $contact */
            $contact = $this->route('contact');

            // Exactly one of value / address must hold for the RESULTING row
            // (mirrors StoreContactRequest and the chk_contacts_value CHECK).
            // A breach here previously surfaced as a raw DB integrity error
            // (HTTP 500); enforce it as a clean 422 instead.
            $currentValue = $contact?->value;
            $currentAddress = $contact?->address !== null;
            $hasValueChange = $this->has('value');
            $hasAddressChange = $this->has('address');

            $resultingValue = $hasValueChange ? $this->input('value') : $currentValue;
            $resultingAddress = ($hasAddressChange ? ($this->input('address') !== null) : $currentAddress);

            if (($resultingValue !== null) === $resultingAddress) {
                $validator->errors()->add('value', 'Provide exactly one of value (phone/email) or address.');
            }

            // An emergency contact must keep a name (and may carry a relation).
            // Stripping the person identity via contactPerson: null was a hole.
            if ($this->has('contactPerson')) {
                $type = $contact?->type;
                $keepsContactPerson = $this->input('contactPerson') !== null;
                $resultingName = $keepsContactPerson ? $this->input('contactPerson.name') : null;

                if ($type === 'emergency_contact' && $resultingName === null) {
                    $validator->errors()->add('contactPerson', 'An emergency contact requires a name.');
                }
            }
        });
    }
}
