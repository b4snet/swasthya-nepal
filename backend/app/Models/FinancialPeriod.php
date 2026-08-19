<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FinancialPeriod extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_LOCKED = 'locked';

    protected $fillable = [
        'tenant_id', 'facility_id', 'name',
        'fiscal_year', 'period_number', 'period_type',
        'start_date', 'end_date', 'status',
        'total_budget_minor', 'total_expenses_minor', 'total_revenue_minor',
        'closed_by_staff_id', 'closed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'fiscal_year' => 'integer',
            'period_number' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'total_budget_minor' => 'integer',
            'total_expenses_minor' => 'integer',
            'total_revenue_minor' => 'integer',
            'closed_at' => 'datetime',
        ];
    }
}
