<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentCycle extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id', 'facility_id', 'treatment_plan_id',
        'cycle_number', 'status', 'scheduled_at', 'started_at', 'completed_at',
        'delay_reason', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'cycle_number' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    public function toxicityRecords(): HasMany
    {
        return $this->hasMany(ToxicityRecord::class, 'treatment_cycle_id');
    }
}
