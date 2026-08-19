<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RtTreatmentMachine extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_DECOMMISSIONED = 'decommissioned';

    protected $fillable = [
        'tenant_id', 'facility_id', 'code', 'name', 'machine_type',
        'manufacturer', 'model', 'energy_range', 'status', 'daily_capacity', 'capabilities',
    ];

    protected function casts(): array
    {
        return ['daily_capacity' => 'integer', 'capabilities' => 'array'];
    }
}
