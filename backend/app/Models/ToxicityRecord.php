<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToxicityRecord extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'treatment_cycle_id', 'patient_id',
        'toxicity_type', 'ctcae_grade', 'description', 'management_action',
        'outcome', 'dose_modified', 'dose_modification', 'onset_at', 'resolved_at',
        'reported_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'dose_modified' => 'boolean',
            'onset_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(TreatmentCycle::class, 'treatment_cycle_id');
    }
}
