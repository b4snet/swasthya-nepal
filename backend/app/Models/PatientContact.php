<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PatientContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient contact (DATABASE.md §3.13): phone, email, address, or
 * emergency contact / next of kin.
 *
 * Exactly one of `value` (phone/email/emergency phone) or `address` (jsonb)
 * is set (CHECK-enforced). One active primary per (patient, type). History
 * is preserved by superseding — a changed contact never disappears.
 */
class PatientContact extends Model
{
    /** @use HasFactory<PatientContactFactory> */
    use HasFactory, HasUuid;

    public const TYPE_PHONE = 'phone';

    public const TYPE_EMAIL = 'email';

    public const TYPE_ADDRESS = 'address';

    public const TYPE_EMERGENCY = 'emergency_contact';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'type',
        'value',
        'address',
        'contact_person',
        'is_primary',
        'valid_from',
        'valid_to',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'contact_person' => 'array',
            'is_primary' => 'boolean',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
