<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModalityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'dailyCapacity' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'status' => ['sometimes', 'in:active,inactive,down'],
            'lockVersion' => ['required', 'integer', 'min:0'],
        ];
    }
}
