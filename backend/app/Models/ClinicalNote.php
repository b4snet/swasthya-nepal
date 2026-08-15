<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ClinicalNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Structured clinical documentation (DATABASE.md §3.19). Drafts may be
 * discarded pre-sign; signed notes are immutable — amendments are new,
 * audited versions (parent_note_id chain).
 *
 * content is a JSON object of structured sections (e.g. complaint, history,
 * examination, assessment, plan).
 */
class ClinicalNote extends Model
{
    /** @use HasFactory<ClinicalNoteFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SIGNED = 'signed';

    public const STATUS_AMENDED = 'amended';

    public const TYPE_CONSULTATION = 'consultation';

    public const TYPE_DISCHARGE = 'discharge';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'encounter_id',
        'note_type',
        'author_staff_id',
        'content',
        'status',
        'signed_at',
        'parent_note_id',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'signed_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Encounter, $this>
     */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class, 'encounter_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'author_staff_id');
    }
}
