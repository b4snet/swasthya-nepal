<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RT plan approval (Phase 15).
 *
 * Tracks the multi-step approval workflow:
 *   1. Physicist check (dose calculation verification)
 *   2. Secondary check (independent verification for VMAT/IMRT/SRS/SBRT)
 *   3. Radiation oncologist approval (clinical responsibility)
 *
 * Each step is an independent audited record. The system clearly
 * distinguishes calculation, validation, approval, and clinical responsibility.
 * Software validation is never represented as clinical approval.
 */
class RtPlanApproval extends Model
{
    use HasFactory, HasUuid;

    public const TYPE_PHYSICIST_CHECK = 'physicist_check';

    public const TYPE_SECONDARY_CHECK = 'secondary_check';

    public const TYPE_RO_APPROVAL = 'ro_approval';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'tenant_id', 'facility_id', 'rt_plan_id',
        'approval_type', 'status', 'decision', 'comments',
        'approved_by_staff_id', 'approved_at', 'checklist',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(RtTreatmentPlan::class, 'rt_plan_id');
    }

    /**
     * Check if this is a secondary check (required for VMAT/IMRT/SRS/SBRT).
     */
    public function isSecondaryCheck(): bool
    {
        return $this->approval_type === self::TYPE_SECONDARY_CHECK;
    }
}
