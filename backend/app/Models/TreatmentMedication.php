<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentMedication extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'treatment_plan_id', 'medication_id',
        'medication_name', 'dose', 'dose_unit', 'route', 'frequency',
        'days_per_cycle', 'cycle_schedule', 'premedication', 'notes',
    ];

    protected function casts(): array
    {
        return ['dose' => 'decimal:4', 'premedication' => 'boolean'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }
}
