<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\MarEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medication-administration-record entry (DATABASE.md §3.27,
 * PRODUCT_REQUIREMENTS §6.5): a scheduled dose of a prescription line for an
 * admission, transitioned scheduled → given | refused | missed | held.
 *
 * One administration per scheduled dose is DB-enforced (partial unique on
 * (tenant_id, prescription_line_id, scheduled_at)); the transition is a
 * compare-and-swap on status. The administering nurse and refusal/miss
 * reason are captured; identity re-confirmation (name + MRN) is a hard
 * requirement of the administer action (CLINICAL_SAFETY.md §190).
 */
class MarEntry extends Model
{
    /** @use HasFactory<MarEntryFactory> */
    use HasFactory, HasUuid;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_GIVEN = 'given';

    public const STATUS_REFUSED = 'refused';

    public const STATUS_MISSED = 'missed';

    public const STATUS_HELD = 'held';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'admission_id',
        'prescription_line_id',
        'scheduled_at',
        'status',
        'administered_by',
        'administered_at',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'administered_at' => 'datetime',
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
     * @return BelongsTo<PrescriptionLine, $this>
     */
    public function prescriptionLine(): BelongsTo
    {
        return $this->belongsTo(PrescriptionLine::class, 'prescription_line_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function administeredBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'administered_by');
    }
}
