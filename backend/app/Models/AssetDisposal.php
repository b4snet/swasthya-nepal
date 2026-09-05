<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asset disposal record (prompt §91). Tracks disposal type, reason,
 * authorization, and performer. Preserves historical record — never
 * delete disposed assets. Tenant+facility scoped, RLS on + FORCED.
 */
class AssetDisposal extends Model
{
    /** @use HasFactory<AssetDisposalFactory> */
    use HasFactory, HasUuid;

    public const TYPE_DONATED = 'donated';
    public const TYPE_RECYCLED = 'recycled';
    public const TYPE_DESTROYED = 'destroyed';
    public const TYPE_SOLD = 'sold';
    public const TYPE_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'asset_id',
        'disposal_type',
        'reason',
        'authorization_ref',
        'authorized_by_staff_id',
        'performed_by_staff_id',
        'disposed_at',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'disposed_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
