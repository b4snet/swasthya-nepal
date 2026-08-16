<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ChecklistTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A facility-defined surgical safety checklist definition
 * (DATABASE.md §3.48, PRODUCT_REQUIREMENTS §6.10) — pre_op / time_out /
 * sign_out / post_op step definitions. Per-procedure completion lives in
 * checklist_items. Tenant+facility scoped, RLS on + FORCED.
 */
class ChecklistTemplate extends Model
{
    /** @use HasFactory<ChecklistTemplateFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const CATEGORY_PRE_OP = 'pre_op';

    public const CATEGORY_TIME_OUT = 'time_out';

    public const CATEGORY_SIGN_OUT = 'sign_out';

    public const CATEGORY_POST_OP = 'post_op';

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
        'category',
        'steps',
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
            'steps' => 'array',
        ];
    }
}
