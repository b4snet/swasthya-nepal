<?php

namespace App\Http\Requests\Room;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Models\Room;
use App\Support\TenantContext;

/**
 * POST /api/v1/wards/{ward}/rooms.
 *
 * The ward is the tenant/facility anchor: a room is created inside its
 * ward's facility — the tenant-safe composite FK makes any mismatch
 * structurally impossible. No facilityId field.
 */
class StoreRoomRequest extends ApiRequest
{
    use HasBranchContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ward = $this->route('ward');
        $context = TenantContext::current();

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'code' => [
                'required',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($ward, $context): void {
                    if ($ward === null) {
                        return;
                    }

                    $exists = Room::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $ward->facility_id)
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A room with this code already exists in this facility.');
                    }
                },
            ],
            'branchId' => $this->branchIdRules($ward?->facility_id),
            'roomType' => ['required', 'in:general,private,semi_private,icu,other'],
            'dailyRateMinor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
