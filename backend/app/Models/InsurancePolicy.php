<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\InsurancePolicyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A patient's coverage under a payer's product (DATABASE.md §3.14):
 * validity, benefits, and the approval linkage used at charge time.
 *
 * Status is a lifecycle (active → expired/cancelled), never a deletion —
 * claims reference coverage at claim time. `lock_version` guards concurrent
 * changes.
 */
class InsurancePolicy extends Model
{
    /** @use HasFactory<InsurancePolicyFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'payer_id',
        'policy_number',
        'coverage_type',
        'valid_from',
        'valid_to',
        'benefits',
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
            'benefits' => 'array',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    /**
     * @return BelongsTo<Payer, $this>
     */
    public function payer(): BelongsTo
    {
        return $this->belongsTo(Payer::class, 'payer_id');
    }
}
