<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RtDoseConstraint extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'rt_structure_id',
        'constraint_type', 'constraint_value', 'constraint_unit',
        'achieved_value', 'met', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'constraint_value' => 'decimal:2',
            'achieved_value' => 'decimal:2',
            'met' => 'boolean',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(RtStructure::class, 'rt_structure_id');
    }
}
