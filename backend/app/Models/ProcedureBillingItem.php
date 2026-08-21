<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ProcedureBillingItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A procedure billing item (Phase 85 — complete revenue cycle): tracks
 * the billable items associated with a surgical/clinical procedure.
 * When marked as charged, a corresponding Charge is created and linked.
 */
class ProcedureBillingItem extends Model
{
    /** @use HasFactory<ProcedureBillingItemFactory> */
    use HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CHARGED = 'charged';

    public const STATUS_WAIVED = 'waived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'patient_id',
        'encounter_id',
        'item_code',
        'description',
        'amount_minor',
        'currency',
        'quantity',
        'tax_rate_bps',
        'status',
        'charge_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'quantity' => 'integer',
            'tax_rate_bps' => 'integer',
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class, 'procedure_id');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class, 'charge_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'procedureId' => $this->procedure_id,
            'patientId' => $this->patient_id,
            'itemCode' => $this->item_code,
            'description' => $this->description,
            'amountMinor' => $this->amount_minor,
            'currency' => $this->currency,
            'quantity' => $this->quantity,
            'taxRateBps' => $this->tax_rate_bps,
            'status' => $this->status,
            'chargeId' => $this->charge_id,
        ];
    }
}
