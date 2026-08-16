<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\TheatreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An operating theatre within a facility (DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10). Tenant+facility scoped, RLS on + FORCED.
 */
class Theatre extends Model
{
    /** @use HasFactory<TheatreFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'status',
        'created_by',
        'updated_by',
    ];
}
