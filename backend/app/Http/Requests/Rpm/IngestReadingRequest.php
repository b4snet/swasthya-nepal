<?php

namespace App\Http\Requests\Rpm;

use App\Http\Requests\ApiRequest;

/**
 * POST /api/v1/rpm/readings — device-adapter batch ingestion (≤100).
 * Each reading is validated and LABELED (validated | flagged | rejected)
 * by RpmService — never silently treated as verified. Idempotent by
 * ingestionId (adapter retries are no-ops).
 */
class IngestReadingRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'readings' => ['required', 'array', 'min:1', 'max:100'],
            'readings.*.deviceIdentifier' => ['required', 'string', 'max:120'],
            'readings.*.ingestionId' => ['nullable', 'string', 'max:120'],
            'readings.*.readingType' => ['required', 'in:bp,pulse,temp,spo2,glucose,weight'],
            'readings.*.value' => ['required', 'array'],
            'readings.*.units' => ['nullable', 'string', 'max:40'],
            'readings.*.measuredAt' => ['nullable', 'date'],
            'readings.*.provenance' => ['nullable', 'array'],
        ];
    }
}
