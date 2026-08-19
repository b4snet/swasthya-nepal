<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RtStructure extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'rt_plan_id',
        'structure_name', 'structure_type', 'volume_cc',
        'mean_dose_cgy', 'max_dose_cgy', 'dvh_data', 'contour_data',
    ];

    protected function casts(): array
    {
        return [
            'volume_cc' => 'decimal:4',
            'mean_dose_cgy' => 'decimal:2',
            'max_dose_cgy' => 'decimal:2',
            'dvh_data' => 'array',
            'contour_data' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RtTreatmentPlan::class, 'rt_plan_id');
    }

    public function doseConstraints(): HasMany
    {
        return $this->hasMany(RtDoseConstraint::class, 'rt_structure_id');
    }
}
