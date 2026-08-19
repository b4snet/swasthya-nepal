<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OncologyEncounter extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'encounter_id', 'oncology_profile_id',
        'encounter_type', 'performance_status', 'clinical_summary',
        'treatment_response', 'plan_notes', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OncologyProfile::class, 'oncology_profile_id');
    }
}
