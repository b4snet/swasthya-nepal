<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An equipment asset through its whole life (PRODUCT_REQUIREMENTS §6.18,
 * DATABASE.md §3.46): category, location, value, warranty, serial/barcode/
 * RFID tag, and an explicit lifecycle (procured → deployed → under_repair →
 * retired). Lifecycle transitions are CAS-guarded on
 * (lifecycle_status, lock_version) — a concurrent transition affects zero
 * rows; a retired asset is terminal. Downtime truthfulness: an asset with an
 * OPEN downtime work order must be under_repair — a machine listed as
 * available while down is a planning hazard (AssetService enforces this).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory, HasUuid;

    public const LIFECYCLE_PROCURED = 'procured';

    public const LIFECYCLE_DEPLOYED = 'deployed';

    public const LIFECYCLE_UNDER_REPAIR = 'under_repair';

    public const LIFECYCLE_RETIRED = 'retired';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'category_id',
        'name',
        'serial_number',
        'rfid_tag',
        'barcode',
        'current_location_id',
        'purchase_value_minor',
        'purchase_date',
        'warranty_until',
        'lifecycle_status',
        'status',
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
            'purchase_value_minor' => 'integer',
            'purchase_date' => 'date',
            'warranty_until' => 'date',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    /**
     * @return HasMany<AssetTransfer, $this>
     */
    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class, 'asset_id');
    }

    /**
     * @return HasMany<WorkOrder, $this>
     */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'asset_id');
    }

    /**
     * @return HasMany<IotReading, $this>
     */
    public function iotReadings(): HasMany
    {
        return $this->hasMany(IotReading::class, 'asset_id');
    }
}
