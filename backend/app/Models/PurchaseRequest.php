<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PurchaseRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A purchase request (PRODUCT_REQUIREMENTS §6.16, DATABASE.md §3.32):
 * department need → approval (requester ≠ approver) → purchase order.
 * Status: draft → submitted → approved | rejected (terminal) → ordered.
 */
class PurchaseRequest extends Model
{
    /** @use HasFactory<PurchaseRequestFactory> */
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ORDERED = 'ordered';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'request_number',
        'requested_by',
        'department_id',
        'status',
        'requested_at',
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
            'requested_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<PurchaseRequestLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseRequestLine::class, 'purchase_request_id');
    }

    /**
     * @return HasOne<PurchaseRequestApproval, $this>
     */
    public function approval(): HasOne
    {
        return $this->hasOne(PurchaseRequestApproval::class, 'purchase_request_id');
    }

    /**
     * Estimated total in integer minor units (the value the approval gates).
     */
    public function estimatedTotalMinor(): int
    {
        return (int) $this->lines()->get()->sum(
            static fn (PurchaseRequestLine $line): int => $line->quantity * $line->estimated_unit_price_minor
        );
    }
}
