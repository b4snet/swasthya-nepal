<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AssetTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only location change for an asset (PRODUCT_REQUIREMENTS §6.18,
 * DATABASE.md §3.46). Transfers are never edited or deleted — the location
 * history is the audit trail and must survive the equipment's life.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class AssetTransfer extends Model
{
    /** @use HasFactory<AssetTransferFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'from_location_id',
        'to_location_id',
        'transferred_at',
        'transferred_by_staff_id',
        'reason',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transferred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }
}
