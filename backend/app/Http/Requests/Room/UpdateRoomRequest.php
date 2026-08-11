<?php

namespace App\Http\Requests\Room;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Models\Room;
use App\Support\TenantContext;

/**
 * PATCH /api/v1/rooms/{room}.
 *
 * Rate changes are financial truth for bed charges and are audited
 * (DATABASE.md §3.25).
 */
class UpdateRoomRequest extends ApiRequest
{
    use HasBranchContext;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $room = $this->route('room');
        $context = TenantContext::current();

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'code' => [
                'sometimes',
                'string',
                'regex:/^[a-z0-9][a-z0-9-]{1,49}$/',
                function (string $attribute, mixed $value, callable $fail) use ($room, $context): void {
                    if ($room === null) {
                        return;
                    }

                    $exists = Room::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('facility_id', $room->facility_id)
                        ->where('id', '!=', $room->getKey())
                        ->whereRaw('lower(code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A room with this code already exists in this facility.');
                    }
                },
            ],
            'branchId' => $this->branchIdRules($room?->facility_id),
            'roomType' => ['sometimes', 'in:general,private,semi_private,icu,other'],
            'dailyRateMinor' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
