<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetLine extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'budget_id',
        'expense_category_id', 'description',
        'allocation_minor', 'spent_minor', 'committed_minor',
        'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'allocation_minor' => 'integer',
            'spent_minor' => 'integer',
            'committed_minor' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function remainingMinor(): int
    {
        return $this->allocation_minor - $this->spent_minor - $this->committed_minor;
    }
}
