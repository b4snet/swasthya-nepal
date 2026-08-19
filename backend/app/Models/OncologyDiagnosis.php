<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OncologyDiagnosis extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'oncology_profile_id',
        'diagnosis_code', 'description', 'cancer_site', 'histology', 'grade',
        'tnm_t', 'tnm_n', 'tnm_m', 'overall_stage', 'diagnosis_type',
        'diagnosed_at', 'diagnosed_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['diagnosed_at' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OncologyProfile::class, 'oncology_profile_id');
    }
}
