<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The append-only, tamper-evident audit trail (DATABASE.md §3.36).
 *
 * Standalone by design: resources are referenced by id + type, never
 * FK-coupled, so history survives resource purges. There is no update/delete
 * path in the application — writes go through AuditLogger only.
 *
 * Chain integrity: each event's event_hash covers the prior event's
 * event_hash plus this event's canonical payload. verifyChain() recomputes
 * every link from the stored rows; ANY modification (payload, actor, time,
 * even a single character) breaks at least one link.
 */
class AuditEvent extends Model
{
    use HasUuid;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_INTEGRATION = 'integration';

    // The append-only table has no created_at/updated_at: occurred_at IS the
    // event time (DATABASE.md §3.36). Eloquent's created-at machinery maps to
    // it; updated_at stays disabled.
    public const CREATED_AT = 'occurred_at';

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'occurred_at',
        'actor_type',
        'actor_id',
        'actor_email',
        'action',
        'resource_type',
        'resource_id',
        'facility_id',
        'payload',
        'ip_address',
        'correlation_id',
        'prev_hash',
        'event_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /**
     * The canonical string this event contributes to the chain — computed
     * identically at insert (AuditLogger) and at verification, so the
     * comparison is exact.
     */
    public function chainPayload(): string
    {
        return implode('|', [
            $this->getKey(),
            (string) $this->tenant_id,
            (string) $this->occurred_at?->toIso8601String(),
            $this->actor_type,
            (string) $this->actor_id,
            (string) $this->actor_email,
            $this->action,
            $this->resource_type,
            (string) $this->resource_id,
            (string) $this->facility_id,
            self::canonicalJson((array) $this->payload),
            (string) $this->ip_address,
            (string) $this->correlation_id,
        ]);
    }

    /**
     * @param  Builder<AuditEvent>  $query
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }

    /**
     * Deterministic JSON for the chain: recursively sorted keys, so the
     * insert-time and verify-time encodings always match regardless of
     * PostgreSQL jsonb key normalization.
     *
     * @param  array<mixed>  $value
     */
    public static function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (is_array($item)) {
                ksort($item);

                return array_map($normalize, $item);
            }

            return $item;
        };

        return json_encode(
            $normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * Recompute the entire chain from the stored rows and report every link
     * that fails. A non-empty result is a tamper-evidence event (SECURITY.md
     * §25, MASTER_RULES.md §19.5) — it is an incident, not a log entry.
     *
     * @return list<int> indexes of broken links (0-based)
     */
    public static function verifyChain(?string $tenantId = null): array
    {
        // Walk OLDEST → NEWEST: each row's hash covers the row before it.
        $query = AuditEvent::query()->orderBy('occurred_at')->orderBy('id');

        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        $events = $query->get();
        $broken = [];
        $previousHash = null;

        foreach ($events->values() as $index => $event) {
            /** @var AuditEvent $event */
            $expected = hash('sha256', ($previousHash ?? '').'|'.$event->chainPayload());
            $previousHash = $event->event_hash;

            if (! hash_equals($expected, (string) $event->event_hash)) {
                $broken[] = $index;
            }
        }

        return $broken;
    }
}
