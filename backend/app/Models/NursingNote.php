<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\NursingNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured nursing note on an admission (DATABASE.md §3.27,
 * PRODUCT_REQUIREMENTS §6.5): draft → signed. Signed notes are immutable
 * clinical record; amendments are a later-phase evolution (mirroring
 * clinical_notes). content is a JSON object of structured sections and is
 * clinical PHI — it never reaches audit payloads.
 */
class NursingNote extends Model
{
    /** @use HasFactory<NursingNoteFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SIGNED = 'signed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'admission_id',
        'author_staff_id',
        'content',
        'status',
        'signed_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
            'signed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class, 'admission_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'author_staff_id');
    }
}
