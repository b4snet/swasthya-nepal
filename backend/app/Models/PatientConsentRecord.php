<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientConsentRecord extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_GRANTED = 'granted';

    public const STATUS_DENIED = 'denied';

    public const STATUS_REVOKED = 'revoked';

    // Data categories matching portal grant scopes
    public const CATEGORIES = [
        'allergies', 'medications', 'diagnoses', 'lab_results', 'radiology',
        'prescriptions', 'documents', 'referrals', 'care_plans', 'immunizations',
        'billing', 'appointments', 'messaging',
    ];

    protected $fillable = [
        'tenant_id', 'facility_id', 'patient_id',
        'data_category', 'consent_status', 'purpose',
        'granted_by', 'granted_by_staff_id', 'granted_at',
        'revoked_at', 'revocation_reason', 'expires_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function isCurrentlyGranted(): bool
    {
        if ($this->consent_status !== self::STATUS_GRANTED) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
