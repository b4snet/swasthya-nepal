<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An implant used during a surgical procedure (PRODUCT_REQUIREMENTS §6.10,
 * prompt §26-28): lot/serial traceability per procedure. The system must
 * answer "which implant lot/serial was used for which patient and procedure."
 * Tenant+facility scoped, RLS on + FORCED.
 */
class Implant extends Model
{
    /** @use HasFactory<ImplantFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'patient_id',
        'implant_name',
        'manufacturer',
        'lot_number',
        'serial_number',
        'implant_location',
        'notes',
        'recorded_by_staff_id',
        'implanted_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'implanted_at' => 'datetime',
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
}
