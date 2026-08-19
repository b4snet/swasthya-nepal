<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RtFraction extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_MISSED = 'missed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'tenant_id', 'facility_id', 'rt_plan_id',
        'fraction_number', 'dose_cgy', 'status', 'scheduled_date', 'notes',
    ];

    protected function casts(): array
    {
        return ['dose_cgy' => 'decimal:2', 'fraction_number' => 'integer'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RtTreatmentPlan::class, 'rt_plan_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(RtFractionSession::class, 'rt_fraction_id');
    }
}
