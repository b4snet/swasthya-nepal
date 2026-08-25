<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Drug-drug interaction rule (PHASE 29, CLINICAL_SAFETY §7).
 *
 * Stores known interactions between medication pairs. The clinical
 * decision-support system checks prescriptions against these rules
 * before finalizing. Interactions are severity-graded:
 *
 *   critical — must not coexist (e.g. contraindicated combination)
 *   major    — requires clinical review before co-prescribing
 *   moderate — informational, clinician awareness
 *
 * Rules are hospital-authorized and versioned. The backend does NOT
 * auto-approve prescriptions that trigger critical interactions.
 */
class DrugInteraction extends Model
{
    /** @use HasFactory<DrugInteractionFactory> */
    use HasFactory, HasUuid;

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_MAJOR = 'major';
    public const SEVERITY_MODERATE = 'moderate';

    protected $fillable = [
        'tenant_id',
        'medication_a_id',
        'medication_b_id',
        'severity',
        'description',
        'clinical_effect',
        'recommendation',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function medicationA(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_a_id');
    }

    public function medicationB(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_b_id');
    }
}
