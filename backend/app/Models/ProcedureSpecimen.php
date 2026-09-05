<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A specimen collected during a surgical procedure, linked to the canonical
 * Laboratory/Pathology workflow (prompt §29-30):
 *
 *   Procedure → Specimen → Laboratory
 *
 * Prevents specimen A from Patient A being associated with Procedure for
 * Patient B (wrong-patient specimen control). Tenant+facility scoped,
 * RLS on + FORCED.
 */
class ProcedureSpecimen extends Model
{
    /** @use HasFactory<ProcedureSpecimenFactory> */
    use HasFactory, HasUuid;

    public const STATUS_COLLECTED = 'collected';
    public const STATUS_SENT = 'sent';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'patient_id',
        'specimen_id',
        'specimen_type',
        'body_site',
        'laterality',
        'status',
        'collected_at',
        'collected_by_staff_id',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
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
}
