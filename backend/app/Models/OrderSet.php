<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned bundle of predefined clinical orders for a specific workflow.
 * Order sets accelerate CPOE by grouping common orders (e.g., "diabetic
 * workup", "pre-procedure labs") into a single selectable template.
 *
 * Every order set is versioned: historical encounters retain the exact
 * version used. The active version is the latest published version.
 *
 * Governance: order sets have an owner, reviewer, and approval state.
 * Draft sets are not visible to clinicians until published.
 */
class OrderSet extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW = 'review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'name',
        'description',
        'specialty',
        'version',
        'status',
        'owner_staff_id',
        'reviewer_staff_id',
        'reviewed_at',
        'approved_at',
        'published_at',
        'retired_at',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'lock_version' => 'integer',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'owner_staff_id');
    }

    /**
     * @return BelongsTo<Staff, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'reviewer_staff_id');
    }

    /**
     * @return HasMany<OrderSetItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderSetItem::class, 'order_set_id');
    }

    /**
     * @return HasMany<OrderSetApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(OrderSetApplication::class, 'order_set_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REVIEW], true);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }
}
