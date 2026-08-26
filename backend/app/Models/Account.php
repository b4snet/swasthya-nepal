<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Chart of Accounts — the canonical account catalog (DATABASE.md §3.58).
 *
 * Every financial event maps to one or more accounts. Accounts are
 * organization-scoped with optional facility override. Types follow
 * standard accounting classification: asset, liability, equity, revenue,
 * expense. Parent accounts support hierarchical reporting.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUuid;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'type',
        'category',
        'parent_id',
        'reporting_category',
        'description',
        'is_cash_account',
        'is_bank_account',
        'effective_from',
        'effective_to',
        'status',
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
            'is_cash_account' => 'boolean',
            'is_bank_account' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'lock_version' => 'integer',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
