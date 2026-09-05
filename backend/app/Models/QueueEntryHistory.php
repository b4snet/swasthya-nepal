<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit trail for queue state transitions. Every call, transfer,
 * skip, recall, completion, and no-show is recorded here. Never updated or
 * deleted — this is the queue provenance record.
 */
class QueueEntryHistory extends Model
{
    use HasUuid;

    protected $table = 'queue_entry_history';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'facility_id', 'queue_entry_id', 'action',
        'from_status', 'to_status', 'department', 'actor_id',
        'reason', 'metadata', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class, 'queue_entry_id');
    }
}
