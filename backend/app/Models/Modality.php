<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ModalityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An imaging machine in a facility's radiology department (DATABASE.md
 * §3.29, PRODUCT_REQUIREMENTS §6.9). The modality is the scheduling
 * resource: studies are assigned to a modality with a slot; daily capacity
 * bounds the schedule. `down` status documents modality downtime (the
 * production fallback surface).
 *
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Modality extends Model
{
    /** @use HasFactory<ModalityFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_DOWN = 'down';

    public const TYPE_XRAY = 'xray';

    public const TYPE_USG = 'usg';

    public const TYPE_CT = 'ct';

    public const TYPE_MRI = 'mri';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'modality_type',
        'daily_capacity',
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
            'daily_capacity' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<Study, $this>
     */
    public function studies(): HasMany
    {
        return $this->hasMany(Study::class, 'modality_id');
    }
}
