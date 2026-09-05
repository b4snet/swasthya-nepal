<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An individual order item within an order set. Each item represents a
 * predefined order (lab test, imaging study, medication, referral) with
 * default values that the clinician may override when applying the set.
 *
 * Items are immutable once the order set version is published.
 */
class OrderSetItem extends Model
{
    use HasFactory, HasUuid;

    /**
     * Order type vocabulary — matches the existing order domain models.
     */
    public const TYPE_LAB = 'lab';

    public const TYPE_IMAGING = 'imaging';

    public const TYPE_MEDICATION = 'medication';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_REFERRAL = 'referral';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'order_set_id',
        'order_type',
        'service_code',
        'service_name',
        'default_quantity',
        'default_frequency',
        'default_duration',
        'default_instructions',
        'default_priority',
        'sort_order',
        'is_optional',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'default_quantity' => 'integer',
            'is_optional' => 'boolean',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OrderSet, $this>
     */
    public function orderSet(): BelongsTo
    {
        return $this->belongsTo(OrderSet::class, 'order_set_id');
    }
}
