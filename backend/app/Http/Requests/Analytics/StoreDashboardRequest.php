<?php

namespace App\Http\Requests\Analytics;

use App\Http\Requests\ApiRequest;

/**
 * POST analytics/dashboards — create a curated KPI dashboard with a role
 * gate (PRODUCT REQUIREMENTS §6.19).
 */
class StoreDashboardRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'roleGate' => ['sometimes', 'array'],
            'roleGate.*' => ['string', 'max:50'],
        ];
    }
}
