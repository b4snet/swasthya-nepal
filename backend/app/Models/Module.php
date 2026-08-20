<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'name', 'description', 'domain', 'category',
        'is_core', 'is_active', 'dependencies', 'required_permissions',
        'nav_config', 'sort_order',
    ];

    protected $casts = [
        'dependencies' => 'array',
        'required_permissions' => 'array',
        'nav_config' => 'array',
        'is_core' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function entitlements()
    {
        return $this->hasMany(ModuleEntitlement::class);
    }
}
