<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormTemplateCategory extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'description', 'icon', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function templates()
    {
        return $this->hasMany(FormTemplate::class, 'category', 'slug');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
