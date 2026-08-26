<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Domain event outbox — reliable event processing (Phase 33).
 *
 * Events are persisted INSIDE the same transaction as the business state
 * change. A worker polls pending events and processes side effects.
 *
 * Lifecycle:
 *   pending → processing → completed
 *                       → failed → retrying → dead
 *
 * The outbox guarantees:
 *   1. No lost events (transactional publish)
 *   2. No false events (committed state = event)
 *   3. Idempotent processing (idempotency_key + attempt tracking)
 */
class DomainEvent extends Model
{
    /** @use HasUuid<DomainEvent> */
    use HasUuid;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DEAD = 'dead';

    protected $fillable = [
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'causer_type',
        'causer_id',
        'facility_id',
        'tenant_id',
        'correlation_id',
        'status',
        'attempt_count',
        'max_attempts',
        'next_attempt_at',
        'processed_at',
        'last_error',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('next_attempt_at', '<=', now());
    }

    public function scopeForProcessing($query)
    {
        return $query->pending()
            ->orderBy('created_at')
            ->limit(50);
    }

    // ── State transitions ──

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'attempt_count' => $this->attempt_count + 1,
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $nextAttempt = $this->attempt_count >= $this->max_attempts
            ? null
            : now()->addSeconds(min(300, pow(2, $this->attempt_count) * 5));

        $this->update([
            'status' => $nextAttempt ? self::STATUS_FAILED : self::STATUS_DEAD,
            'last_error' => $error,
            'next_attempt_at' => $nextAttempt,
        ]);
    }

    // ── Helpers ──

    public function isRetryable(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_PENDING])
            && $this->attempt_count < $this->max_attempts;
    }

    public function isDead(): bool
    {
        return $this->status === self::STATUS_DEAD;
    }
}
