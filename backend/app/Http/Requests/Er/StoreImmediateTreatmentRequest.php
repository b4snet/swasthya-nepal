<?php

namespace App\Http\Requests\Er;

use Illuminate\Foundation\Http\FormRequest;

final class StoreImmediateTreatmentRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
