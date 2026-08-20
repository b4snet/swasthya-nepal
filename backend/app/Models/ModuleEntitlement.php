<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ModuleEntitlement extends Model
{
    use HasUuids;

    protected $fillable = [
        'organization_id', 'facility_id', 'module_id', 'status',
        'activation_state', 'configuration', 'internal_commercial',
        'activated_at', 'expires_at', 'source', 'created_by',
    ];

    protected $casts = [
        'configuration' => 'array',
        'internal_commercial' => 'array',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function facility()
    {
        return $this->belongsTo(Facility::class);
    }

    public function isEnabled(): bool
    {
        return $this->status === 'enabled' && $this->activation_state === 'active';
    }
}
