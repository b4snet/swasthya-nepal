<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PurchaseRequestLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a purchase request: medication, quantity, and the estimated
 * unit price (integer minor units) that drives the approval value.
 */
class PurchaseRequestLine extends Model
{
    /** @use HasFactory<PurchaseRequestLineFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'purchase_request_id',
        'medication_id',
        'quantity',
        'estimated_unit_price_minor',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'estimated_unit_price_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }

    /**
     * @return BelongsTo<Medication, $this>
     */
    public function medication(): BelongsTo
    {
        return $this->belongsTo(Medication::class, 'medication_id');
    }
}
