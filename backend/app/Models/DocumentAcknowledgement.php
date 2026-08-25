<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAcknowledgement extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_READ = 'read';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    protected $fillable = [
        'tenant_id', 'document_id', 'user_id', 'staff_id',
        'status', 'read_at', 'acknowledged_at', 'ip_address',
        'user_agent', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HospitalDocument::class, 'document_id');
    }
}
