<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Budget extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_ARCHIVED = 'archived';

    public const TYPE_OPERATIONAL = 'operational';

    public const TYPE_CAPITAL = 'capital';

    public const TYPE_PROJECT = 'project';

    protected $fillable = [
        'tenant_id', 'facility_id', 'department_id',
        'budget_code', 'name', 'description', 'budget_type',
        'fiscal_year', 'status', 'total_allocation_minor',
        'spent_minor', 'committed_minor',
        'created_by_staff_id', 'approved_by_staff_id', 'approved_at', 'closed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'fiscal_year' => 'integer',
            'total_allocation_minor' => 'integer',
            'spent_minor' => 'integer',
            'committed_minor' => 'integer',
            'approved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'budget_id');
    }

    public function remainingMinor(): int
    {
        return $this->total_allocation_minor - $this->spent_minor - $this->committed_minor;
    }

    public function utilizationPercent(): float
    {
        if ($this->total_allocation_minor <= 0) {
            return 0.0;
        }

        return round(($this->spent_minor / $this->total_allocation_minor) * 100, 2);
    }
}
