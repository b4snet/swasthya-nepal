<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\CompatibilityResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An ABO/Rh + antibody compatibility check against the patient's record
 * (DATABASE.md §3.50, PRODUCT_REQUIREMENTS §6.12), run BEFORE issue.
 * Tenant+facility scoped, RLS on + FORCED.
 */
class CompatibilityResult extends Model
{
    /** @use HasFactory<CompatibilityResultFactory> */
    use HasFactory, HasUuid;

    public const RESULT_COMPATIBLE = 'compatible';

    public const RESULT_INCOMPATIBLE = 'incompatible';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'patient_blood_group',
        'patient_rh_factor',
        'abo_rh_compatible',
        'antibody_screen',
        'result',
        'notes',
        'checked_at',
        'checked_by_staff_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abo_rh_compatible' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
