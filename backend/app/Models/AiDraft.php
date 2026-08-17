<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AiDraftFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A grounded assistive output (DATABASE.md §3.57, AI_RULES.md §2–3, §9):
 * a documentation draft or summary pinned to the model id/version that
 * produced it and tied to its record sources (`source_refs`). A draft NEVER
 * mutates a clinical record on its own — it becomes usable only after a
 * clinician SIGNS it (status signed + signer). No autonomous-action path.
 */
class AiDraft extends Model
{
    /** @use HasFactory<AiDraftFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'patient_id',
        'encounter_id',
        'function',
        'tier',
        'model_id',
        'model_version',
        'source_refs',
        'output',
        'confidence',
        'status',
        'signer_staff_id',
        'signed_at',
        'correlation_id',
        'lock_version',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'source_refs' => 'array',
            'confidence' => 'float',
            'signed_at' => 'datetime',
            'lock_version' => 'integer',
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
