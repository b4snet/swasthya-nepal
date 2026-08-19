<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtFractionSession extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_INTERRUPTED = 'interrupted';

    protected $fillable = [
        'tenant_id', 'facility_id', 'rt_fraction_id', 'machine_id',
        'status', 'session_start', 'session_end', 'delivered_dose_cgy',
        'interrupt_reason', 'notes', 'delivered_by_staff_id',
    ];

    protected function casts(): array
    {
        return [
            'delivered_dose_cgy' => 'decimal:2',
            'session_start' => 'datetime',
            'session_end' => 'datetime',
        ];
    }

    public function fraction(): BelongsTo
    {
        return $this->belongsTo(RtFraction::class, 'rt_fraction_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(RtTreatmentMachine::class, 'machine_id');
    }
}
