<?php

namespace App\Http\Requests\IpdNursing;

use App\Http\Requests\ApiRequest;
use App\Models\VitalObservation;
use Illuminate\Validation\Rule;

/**
 * POST admissions/{admission}/vitals — a vital observation with a typed
 * value shape per measurement (DATABASE.md §3.27): BP has systolic/
 * diastolic, pulse/temp/spo2/weight carry value + unit, score carries value
 * + scale. is_abnormal is the later-phase CDSS-derived flag and is never
 * client-supplied.
 */
class StoreVitalObservationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace_recursive([
            'type' => ['required', 'string', Rule::in([
                VitalObservation::TYPE_BP,
                VitalObservation::TYPE_PULSE,
                VitalObservation::TYPE_TEMP,
                VitalObservation::TYPE_SPO2,
                VitalObservation::TYPE_WEIGHT,
                VitalObservation::TYPE_SCORE,
            ])],
            'value' => ['required', 'array'],
            'measuredAt' => ['sometimes', 'date', 'date_format:Y-m-d\TH:i:sP'],
        ], $this->valueRules((string) $this->input('type')));
    }

    /**
     * Per-type value validation — the measurement shape is part of the
     * contract, so a malformed vital is a 422 at the boundary.
     *
     * @return array<string, mixed>
     */
    private function valueRules(string $type): array
    {
        return match ($type) {
            'bp' => [
                'value.systolic' => ['required', 'integer', 'min:0', 'max:400'],
                'value.diastolic' => ['required', 'integer', 'min:0', 'max:400'],
            ],
            'pulse' => [
                'value.value' => ['required', 'integer', 'min:0', 'max:400'],
                'value.unit' => ['required', 'string', Rule::in(['bpm'])],
            ],
            'temp' => [
                'value.value' => ['required', 'numeric', 'min:-50', 'max:60'],
                'value.unit' => ['required', 'string', Rule::in(['c', 'f'])],
            ],
            'spo2' => [
                'value.value' => ['required', 'integer', 'min:0', 'max:100'],
                'value.unit' => ['required', 'string', Rule::in(['percent'])],
            ],
            'weight' => [
                'value.value' => ['required', 'numeric', 'min:0', 'max:500'],
                'value.unit' => ['required', 'string', Rule::in(['kg'])],
            ],
            'score' => [
                'value.value' => ['required', 'numeric'],
                'value.scale' => ['required', 'string', 'max:50'],
            ],
            default => [],
        };
    }
}
