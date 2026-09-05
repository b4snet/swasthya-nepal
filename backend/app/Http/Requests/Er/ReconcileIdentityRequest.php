<?php

namespace App\Http\Requests\Er;

use Illuminate\Foundation\Http\FormRequest;

final class ReconcileIdentityRequest extends FormRequest
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
        return [
            'targetPatientId' => ['required', 'string', 'exists:patients,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
