<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SecureMessage extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_UNREAD = 'unread';

    public const STATUS_READ = 'read';

    public const STATUS_ARCHIVED = 'archived';

    public const CATEGORY_GENERAL = 'general';

    public const CATEGORY_CLINICAL = 'clinical';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_APPOINTMENT = 'appointment';

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id',
        'sender_staff_id', 'sender_is_patient',
        'recipient_staff_id', 'recipient_is_patient',
        'subject', 'body', 'status', 'category',
        'related_encounter_id', 'phi_safe', 'read_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'sender_is_patient' => 'boolean',
            'recipient_is_patient' => 'boolean',
            'phi_safe' => 'boolean',
            'metadata' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
