<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An asset category (PRODUCT_REQUIREMENTS §6.18, DATABASE.md §3.46):
 * e.g., ventilator, imaging, beds. Soft-deletable (active-scope partial
 * unique on code); a category with assets cannot be deleted (RESTRICT FK).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class AssetCategory extends Model
{
    /** @use HasFactory<AssetCategoryFactory> */
    use HasFactory, HasUuid;

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
