<?php

namespace App\Http\Requests\Bed;

use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\HasBranchContext;
use App\Models\Bed;
use App\Support\BedStatus;
use App\Support\TenantContext;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/rooms/{room}/beds.
 *
 * The room is the tenant/facility anchor. bed_code is unique per
 * (tenant, room) — full uniqueness, beds never soft-delete (DATABASE.md
 * §3.26). Creation cannot start a bed `occupied` — that is the admission
 * workflow's job (Phase 8).
 */
class StoreBedRequest extends ApiRequest
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
            'bedCode' => [
                'required',
                'string',
                'max:20',
                function (string $attribute, mixed $value, callable $fail) use ($room, $context): void {
                    if ($room === null) {
                        return;
                    }

                    $exists = Bed::query()
                        ->where('tenant_id', $context->tenantId())
                        ->where('room_id', $room->getKey())
                        ->whereRaw('lower(bed_code) = ?', [strtolower((string) $value)])
                        ->exists();

                    if ($exists) {
                        $fail('A bed with this code already exists in this room.');
                    }
                },
            ],
            'branchId' => $this->branchIdRules($room?->facility_id),
            'status' => [
                'sometimes',
                Rule::in(BedStatus::VALID),
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value === BedStatus::OCCUPIED) {
                        $fail(BedStatus::rejectionReason(BedStatus::OCCUPIED));
                    }
                },
            ],
        ];
    }
}
