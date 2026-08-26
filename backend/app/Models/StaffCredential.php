<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffCredential extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'tenant_id', 'facility_id', 'staff_id', 'credential_type',
        'credential_code', 'title', 'issuing_authority',
        'issue_date', 'expiry_date', 'status', 'document_id',
        'verified_by', 'verified_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }
}
