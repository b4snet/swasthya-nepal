<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceBooking extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const RESOURCE_TYPE_OT = 'ot';

    public const RESOURCE_TYPE_IMAGING = 'imaging';

    public const RESOURCE_TYPE_EQUIPMENT = 'equipment';

    public const RESOURCE_TYPE_ROOM = 'room';

    public const RESOURCE_TYPE_BED = 'bed';

    protected $fillable = [
        'tenant_id', 'facility_id', 'resource_type', 'resource_id',
        'booking_code', 'title', 'description', 'patient_id',
        'encounter_id', 'appointment_id', 'provider_staff_id',
        'starts_at', 'ends_at', 'status', 'notes',
        'prepared_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'provider_staff_id');
    }
}
