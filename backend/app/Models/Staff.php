<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\HasUuid;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Employment/clinical identity within a tenant (DATABASE.md §3.10): who the
 * person is to the hospital, distinct from the global login account (users
 * are global, DATABASE.md §1.3).
 *
 * Tenant-scoped (tenant_id NOT NULL). Never soft-deleted: departure is a
 * status — clinical history references the clinician and must persist.
 *
 * The license number is encrypted at rest (SECURITY.md §12): the column
 * `license_number_encrypted` holds ciphertext; the attribute reads back as
 * plaintext through the EncryptedString cast. It is never logged or written
 * to audit payloads.
 */
class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ON_LEAVE = 'on_leave';

    public const STATUS_DEPARTED = 'departed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'department_id',
        'user_id',
        'employee_code',
        'full_name',
        'designation',
        'license_number_encrypted',
        'status',
        'hire_date',
        'settings',
        // Doctor profile fields (Phase 79)
        'specialty',
        'sub_specialty',
        'consultation_fee',
        'consultation_duration_minutes',
        'bio',
        'accepts_new_patients',
        'profile_image_url',
        'available_days',
        'consultation_types',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'license_number_encrypted' => EncryptedString::class,
            'settings' => 'array',
            'hire_date' => 'date',
            'consultation_fee' => 'decimal:2',
            'consultation_duration_minutes' => 'integer',
            'accepts_new_patients' => 'boolean',
            'available_days' => 'array',
            'consultation_types' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
