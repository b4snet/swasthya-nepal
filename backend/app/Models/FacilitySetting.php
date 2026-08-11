<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\FacilitySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Facility configuration as data (PRODUCT_REQUIREMENTS §5.5, MASTER_RULES.md
 * §1.3): one row per setting key, jsonb value, versioned — every change bumps
 * `version` and is audited with the old and new values.
 *
 * Tenant-scoped (tenant_id NOT NULL). Never soft-deleted: removing a setting
 * is a state change and is audited too.
 */
class FacilitySetting extends Model
{
    /** @use HasFactory<FacilitySettingFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'key',
        'value',
        'version',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
