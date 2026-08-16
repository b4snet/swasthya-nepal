<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class StoreRadiologyOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'testIds' => ['required', 'array', 'min:1'],
            'testIds.*' => ['required', 'uuid'],
            'priority' => ['sometimes', 'in:routine,urgent,stat'],
            'clinicalIndication' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'testIds' => 'test ids',
            'priority' => 'priority',
            'clinicalIndication' => 'clinical indication',
        ];
    }
}
