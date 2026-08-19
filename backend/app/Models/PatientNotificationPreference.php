<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientNotificationPreference extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id',
        'email_enabled', 'sms_enabled', 'push_enabled',
        'appointment_reminders', 'result_notifications',
        'billing_notifications', 'messaging_notifications',
        'marketing_opt_out', 'preferred_language', 'timezone',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'email_enabled' => 'boolean',
            'sms_enabled' => 'boolean',
            'push_enabled' => 'boolean',
            'appointment_reminders' => 'boolean',
            'result_notifications' => 'boolean',
            'billing_notifications' => 'boolean',
            'messaging_notifications' => 'boolean',
            'marketing_opt_out' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
