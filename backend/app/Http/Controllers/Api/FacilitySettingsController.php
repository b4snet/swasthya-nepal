<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FacilitySettings\UpdateFacilitySettingsRequest;
use App\Models\Facility;
use App\Models\FacilitySetting;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Facility configuration as data (PRODUCT_REQUIREMENTS §5.5, MASTER_RULES.md
 * §1.3): key/value settings, versioned — every change bumps the version and
 * writes an audit event with old and new values.
 *
 * Settings are never deleted silently: removing a key is itself a state
 * change and is audited with the last value.
 */
final class FacilitySettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: false);

        $settings = FacilitySetting::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (FacilitySetting $setting): array => [
                $setting->key => [
                    'value' => $setting->value,
                    'version' => $setting->version,
                    'updatedAt' => $setting->updated_at?->toIso8601String(),
                ],
            ]);

        return Envelope::success(data: $settings, request: $request);
    }

    public function update(UpdateFacilitySettingsRequest $request, Facility $facility): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: true);

        $context = TenantContext::current();
        $changes = [];

        foreach ($request->validated('settings') as $key => $value) {
            $setting = FacilitySetting::query()
                ->where('tenant_id', $facility->tenant_id)
                ->where('facility_id', $facility->getKey())
                ->where('key', $key)
                ->first();

            if ($setting === null) {
                $changes[$key] = [null, $value];

                FacilitySetting::query()->create([
                    'tenant_id' => $facility->tenant_id,
                    'facility_id' => $facility->getKey(),
                    'key' => $key,
                    'value' => $value,
                    'version' => 1,
                    'updated_by' => $context->user?->getKey(),
                ]);

                continue;
            }

            $changes[$key] = [$setting->value, $value];
            $setting->value = $value;
            $setting->version += 1;
            $setting->updated_by = $context->user?->getKey();
            $setting->save();
        }

        $this->audit->record(
            'facility.settings.updated',
            'facility_settings',
            $facility->getKey(),
            ['changes' => $changes],
            $request,
        );

        $settings = FacilitySetting::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->orderBy('key')
            ->get()
            ->mapWithKeys(fn (FacilitySetting $setting): array => [
                $setting->key => [
                    'value' => $setting->value,
                    'version' => $setting->version,
                    'updatedAt' => $setting->updated_at?->toIso8601String(),
                ],
            ]);

        return Envelope::success(data: $settings, request: $request);
    }

    public function destroy(Request $request, Facility $facility, string $key): JsonResponse
    {
        AccessCheck::facility($facility->getKey(), write: true);

        if (preg_match('/^[a-z][a-z0-9._-]{1,99}$/', $key) !== 1) {
            return Envelope::error(
                'VALIDATION_ERROR',
                sprintf('Setting key "%s" is not a valid identifier.', $key),
                422,
                request: $request,
            );
        }

        $setting = FacilitySetting::query()
            ->where('tenant_id', $facility->tenant_id)
            ->where('facility_id', $facility->getKey())
            ->where('key', $key)
            ->first();

        if ($setting === null) {
            return Envelope::error('NOT_FOUND', 'Setting not found.', 404, request: $request);
        }

        $lastValue = $setting->value;
        $setting->delete();

        $this->audit->record(
            'facility.settings.deleted',
            'facility_settings',
            $facility->getKey(),
            ['key' => $key, 'lastValue' => $lastValue],
            $request,
        );

        return response()->json(null, 204);
    }
}
