<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A curated KPI dashboard per role (DATABASE.md §3.51, PRODUCT REQUIREMENTS
 * §6.19): a named composition of KPI definitions with a role gate. The
 * dashboard is only a view definition — every number drills to real
 * observed snapshots, never fabricated metrics. Tenant+facility scoped,
 * RLS on + FORCED.
 */
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'role_gate',
        'is_active',
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
            'role_gate' => 'array',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<DashboardKpi, $this>
     */
    public function kpis(): HasMany
    {
        return $this->hasMany(DashboardKpi::class, 'dashboard_id');
    }
}
