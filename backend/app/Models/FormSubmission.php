<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormSubmission extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    // ── Statuses ──
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_PRINTED = 'printed';

    public const STATUS_SIGNED = 'signed';

    protected $fillable = [
        'tenant_id', 'facility_id', 'template_id', 'template_version',
        'patient_id', 'encounter_id', 'admission_id', 'appointment_id',
        'data', 'document_number', 'status',
        'submitted_by', 'submitted_by_type', 'submitted_at',
        'verified_by', 'verified_at',
        'approved_by', 'approved_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
        'print_count', 'last_printed_at', 'last_printed_by',
        'import_id', 'import_row',
        'metadata',
    ];

    protected $casts = [
        'data' => 'array',
        'metadata' => 'array',
        'template_version' => 'integer',
        'print_count' => 'integer',
        'submitted_at' => 'datetime',
        'verified_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'last_printed_at' => 'datetime',
    ];

    // ── Relationships ──
    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'template_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class, 'admission_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(FormSignature::class, 'submission_id');
    }

    // ── Scopes ──
    public function scopeDraft($query)
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeSubmitted($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeForPatient($query, string $patientId)
    {
        return $query->where('patient_id', $patientId);
    }

    public function scopeForEncounter($query, string $encounterId)
    {
        return $query->where('encounter_id', $encounterId);
    }

    public function scopeForTemplate($query, string $templateId)
    {
        return $query->where('template_id', $templateId);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // ── Helpers ──
    public function markSubmitted(): void
    {
        $this->update([
            'status' => self::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);
    }

    public function markVerified(string $verifierId): void
    {
        $this->update([
            'status' => self::STATUS_VERIFIED,
            'verified_by' => $verifierId,
            'verified_at' => now(),
        ]);
    }

    public function markApproved(string $approverId): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'approved_by' => $approverId,
            'approved_at' => now(),
        ]);
    }

    public function markCancelled(string $userId, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_by' => $userId,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }

    public function recordPrint(string $userId): void
    {
        $this->increment('print_count');
        $this->update([
            'last_printed_at' => now(),
            'last_printed_by' => $userId,
        ]);
    }

    /**
     * Get the form field value by key.
     */
    public function getField(string $key): mixed
    {
        return data_get($this->data, $key);
    }

    /**
     * Set a form field value.
     */
    public function setField(string $key, mixed $value): void
    {
        data_set($this->data, $key, $value);
        $this->save();
    }
}
