<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    public const TYPE_OPERATIONAL = 'operational';

    public const TYPE_CAPITAL = 'capital';

    public const TYPE_PROJECT = 'project';

    protected $fillable = [
        'tenant_id', 'facility_id', 'budget_id', 'budget_line_id',
        'expense_category_id', 'reference_number', 'description',
        'amount_minor', 'currency', 'status', 'expense_type',
        'vendor_id', 'invoice_number', 'expense_date', 'payment_date',
        'payment_method', 'payment_reference',
        'requested_by_staff_id', 'approved_by_staff_id', 'approved_at',
        'rejection_reason', 'notes', 'attachments', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'attachments' => 'array',
            'amount_minor' => 'integer',
            'expense_date' => 'datetime',
            'payment_date' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class, 'budget_id');
    }

    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'budget_line_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}
