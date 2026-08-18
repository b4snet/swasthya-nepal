<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\VendorContractFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A vendor contract (PRODUCT_REQUIREMENTS §6.16: "POs checked against
 * contract prices"): a negotiated unit price (integer minor units) for a
 * medication+vendor within a validity window. When a contract is ACTIVE for
 * (vendor, medication) at PO issue, the PO line price MUST equal the
 * contract price — a deviation is refused, never silently recorded.
 */
class VendorContract extends Model
{
    /** @use HasFactory<VendorContractFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'vendor_id',
        'medication_id',
        'unit_price_minor',
        'valid_from',
        'valid_to',
        'terms',
        'status',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
