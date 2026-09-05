<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single fluid balance entry (PRODUCT_REQUIREMENTS §6.11, prompt §57):
 * records intake or output for an ICU admission. Each entry is attributable
 * to a source, author, and timestamp. Never interprets clinical significance.
 * Tenant+facility scoped, RLS on + FORCED.
 */
class FluidBalanceEntry extends Model
{
    /** @use HasFactory<FluidBalanceEntryFactory> */
    use HasFactory, HasUuid;

    public const DIRECTION_INTAKE = 'intake';
    public const DIRECTION_OUTPUT = 'output';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'icu_admission_id',
        'direction',
        'fluid_type',
        'volume_ml',
        'source',
        'notes',
        'recorded_at',
        'recorded_by_staff_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(IcuAdmission::class, 'icu_admission_id');
    }
}
