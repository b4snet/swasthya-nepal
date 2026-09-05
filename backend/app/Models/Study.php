<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\StudyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One imaging study per radiology order (DATABASE.md §3.29,
 * PRODUCT_REQUIREMENTS §6.9). The study tracks the imaging lifecycle on the
 * SHARED order surface (a `lab_orders` row with a category='radiology'
 * item):
 *
 *   ordered → scheduled (modality + slot) → arrived → in_progress → acquired
 *   → pending_interpretation → reported (a verified final report exists)
 *   → verified → released_to_clinician
 *
 * Terminal statuses: cancelled, rejected (both require reason).
 * Images live in PACS — this table carries only DICOM/pixel *references*
 * (image_references), never pixels, and a report can only be released
 * against a study that exists (traceability: report → study → modality → order).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Study extends Model
{
    /** @use HasFactory<StudyFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_ACQUIRED = 'acquired';

    public const STATUS_PENDING_INTERPRETATION = 'pending_interpretation';

    public const STATUS_PERFORMED = 'performed';

    public const STATUS_REPORTED = 'reported';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_RELEASED_TO_CLINICIAN = 'released_to_clinician';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'lab_order_id',
        'accession_number',
        'modality_id',
        'status',
        'ordered_at',
        'scheduled_at',
        'rescheduled_at',
        'procedure_started_at',
        'performed_at',
        'performed_by_staff_id',
        'assigned_technician_id',
        'cancel_reason',
        'preparation_instructions',
        'reschedule_reason',
        'acquisition_notes',
        'repeat_acquisition_reason',
        'report_released_at',
        'released_to_clinician_at',
        'charge_id',
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
            'ordered_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'procedure_started_at' => 'datetime',
            'performed_at' => 'datetime',
            'report_released_at' => 'datetime',
            'released_to_clinician_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LabOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class, 'lab_order_id');
    }

    /**
     * @return BelongsTo<Modality, $this>
     */
    public function modality(): BelongsTo
    {
        return $this->belongsTo(Modality::class, 'modality_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assigned_technician_id');
    }

    /**
     * @return HasMany<RadiologyReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(RadiologyReport::class, 'study_id');
    }

    /**
     * @return HasMany<ImageReference, $this>
     */
    public function imageReferences(): HasMany
    {
        return $this->hasMany(ImageReference::class, 'study_id');
    }

    /**
     * @return HasMany<StudyEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(StudyEvent::class, 'study_id');
    }

    /**
     * @return HasMany<Charge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    /**
     * Generate an accession number: YYMMDD-XXXX (tenant+facility unique)
     */
    public static function generateAccessionNumber(string $tenantId, string $facilityId): string
    {
        $date = now()->format('ymd');
        $sequence = 1;

        // Find max sequence for today
        $latest = self::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('accession_number', 'like', $date . '-%')
            ->orderByDesc('accession_number')
            ->first();

        if ($latest && $latest->accession_number) {
            $parts = explode('-', $latest->accession_number);
            if (count($parts) === 2 && is_numeric($parts[1])) {
                $sequence = (int)$parts[1] + 1;
            }
        }

        return $date . '-' . str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
    }
}