<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IcuBedFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An ICU bed with acuity-based assignment (DATABASE.md §3.49,
 * PRODUCT_REQUIREMENTS §6.11). Tenant+facility scoped, RLS on + FORCED.
 */
class IcuBed extends Model
{
    /** @use HasFactory<IcuBedFactory> */
    use HasFactory, HasUuid;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_OUT_OF_SERVICE = 'out_of_service';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'bed_code',
        'status',
        'acuity_supported',
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
            'lock_version' => 'integer',
        ];
    }
}
