<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PurchaseRequestApprovalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The approval row for a purchase request (DATABASE.md §3.32: approver,
 * step, decision, at). One row per request (single-level approval); the
 * approver must differ from the requester (segregation of duties).
 */
class PurchaseRequestApproval extends Model
{
    /** @use HasFactory<PurchaseRequestApprovalFactory> */
    use HasFactory, HasUuid;

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'purchase_request_id',
        'approver_id',
        'decision',
        'reason',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class, 'purchase_request_id');
    }
}
