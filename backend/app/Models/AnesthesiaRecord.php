<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\AnesthesiaRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An anesthesia record for a surgical procedure (DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10). Tenant+facility scoped, RLS on + FORCED.
 */
class AnesthesiaRecord extends Model
{
    /** @use HasFactory<AnesthesiaRecordFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'anesthetist_staff_id',
        'anesthesia_type',
        'started_at',
        'ended_at',
        'status',
        'notes',
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
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }
}
