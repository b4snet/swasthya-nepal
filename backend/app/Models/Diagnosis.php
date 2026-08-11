<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DiagnosisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A diagnosis (DATABASE.md §3.18): coded where available (ICD readiness),
 * typed (provisional, differential, final). A diagnosis is a clinical fact
 * — never soft-deleted, status is the lifecycle.
 */
class Diagnosis extends Model
{
    /** @use HasFactory<DiagnosisFactory> */
    use HasFactory, HasUuid;

    public const TYPE_PROVISIONAL = 'provisional';

    public const TYPE_DIFFERENTIAL = 'differential';

    public const TYPE_FINAL = 'final';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_RULED_OUT = 'ruled_out';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'encounter_id',
        'code',
        'coding_system',
        'description',
        'diagnosis_type',
        'is_primary',
        'onset_date',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'onset_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }
}
