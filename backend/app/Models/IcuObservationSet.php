<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IcuObservationSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One high-frequency ICU observation set (DATABASE.md §3.49,
 * PRODUCT_REQUIREMENTS §6.11). Append-only; the warning score is COMPUTED
 * from these values (never hand-entered). Tenant+facility scoped,
 * RLS on + FORCED.
 */
class IcuObservationSet extends Model
{
    /** @use HasFactory<IcuObservationSetFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'icu_admission_id',
        'observed_at',
        'observed_by_staff_id',
        'values',
        'notes',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'observed_at' => 'datetime',
            'values' => 'array',
        ];
    }
}
