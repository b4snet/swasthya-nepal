<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A versioned operative note (PRODUCT_REQUIREMENTS §6.10, prompt §33-34):
 * final operative notes are clinical records. Corrections preserve the
 * original, corrected version, reason, author, and timestamps. One signed
 * note per procedure at a time; amendments create new rows. Tenant+facility
 * scoped, RLS on + FORCED.
 */
class OperativeNote extends Model
{
    /** @use HasFactory<OperativeNoteFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_CORRECTED = 'corrected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'patient_id',
        'content',
        'status',
        'parent_note_id',
        'correction_reason',
        'authored_by_staff_id',
        'authored_at',
        'signed_by_staff_id',
        'signed_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authored_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class, 'procedure_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function parentNote(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_note_id');
    }
}
