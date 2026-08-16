<?php

namespace App\Http\Requests\Radiology;

use Illuminate\Foundation\Http\FormRequest;

class StoreModalityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'modalityType' => ['required', 'in:xray,usg,ct,mri,fluoroscopy,mammography,other'],
            'dailyCapacity' => ['required', 'integer', 'min:0', 'max:1000'],
            'status' => ['sometimes', 'in:active,inactive,down'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        return [
            'code' => 'code',
            'name' => 'name',
            'modalityType' => 'modality type',
            'dailyCapacity' => 'daily capacity',
            'status' => 'status',
        ];
    }
}
