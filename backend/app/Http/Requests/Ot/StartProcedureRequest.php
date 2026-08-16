<?php

namespace App\Http\Requests\Ot;

use App\Http\Requests\ApiRequest;

/**
 * POST procedure-requests/{request}/start — start the case: create the
 * procedure record and snapshot the safety checklist
 * (PRODUCT_REQUIREMENTS §6.10).
 */
class StartProcedureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checklistTemplateId' => ['required', 'string', 'uuid'],
            'surgeonStaffId' => ['nullable', 'string', 'uuid'],
        ];
    }
}
