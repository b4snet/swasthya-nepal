<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OnboardingSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'facility_id', 'created_by', 'status',
        'current_step', 'total_steps', 'step_data', 'selected_modules',
        'module_configurations', 'organization_data', 'facility_data',
        'activated_at',
    ];

    protected $casts = [
        'step_data' => 'array',
        'selected_modules' => 'array',
        'module_configurations' => 'array',
        'organization_data' => 'array',
        'facility_data' => 'array',
        'activated_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
