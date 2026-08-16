<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IotReadingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An RFID/IoT-ready reading for an asset (PRODUCT_REQUIREMENTS §6.18,
 * DATABASE.md §3.46): tag/location/condition/usage feeds. The data model is
 * designed NOW (append-only, typed readings); device integration arrives in
 * Phase 3 with a real integration — nothing is faked here. Manual readings
 * may be recorded so the model is exercised end to end.
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class IotReading extends Model
{
    /** @use HasFactory<IotReadingFactory> */
    use HasFactory, HasUuid;

    public const TYPE_LOCATION = 'location';

    public const TYPE_CONDITION = 'condition';

    public const TYPE_USAGE = 'usage';

    public const SOURCE_RFID = 'rfid';

    public const SOURCE_DEVICE = 'device';

    public const SOURCE_MANUAL = 'manual';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'reading_type',
        'reading_value',
        'tag_id',
        'read_at',
        'source',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reading_value' => 'array',
            'read_at' => 'datetime',
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
