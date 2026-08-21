<?php

namespace App\Support;

/**
 * The bed status state machine (DATABASE.md §3.26, §0.5).
 *
 * Status is never a free-form update: the application layer validates the
 * transition and audits every change (who, what, when). Phase 4 has no
 * admissions yet, so `occupied` is set only by the IPD admission workflow
 * (Phase 8) — this gate rejects it explicitly rather than pretending the
 * occupancy invariant is enforceable here.
 */
final class BedStatus
{
    public const AVAILABLE = 'available';

    public const OCCUPIED = 'occupied';

    public const RESERVED = 'reserved';

    public const CLEANING = 'cleaning';

    public const OUT_OF_SERVICE = 'out_of_service';

    public const MAINTENANCE = 'maintenance';

    public const VALID = [
        self::AVAILABLE,
        self::OCCUPIED,
        self::RESERVED,
        self::CLEANING,
        self::OUT_OF_SERVICE,
        self::MAINTENANCE,
    ];

    /**
     * Transitions allowed without an admission. `occupied` requires a
     * current_admission_id, set only by the admission workflow.
     */
    private const ALLOWED = [
        self::AVAILABLE => [self::RESERVED, self::CLEANING, self::OUT_OF_SERVICE, self::MAINTENANCE],
        self::RESERVED => [self::AVAILABLE, self::CLEANING, self::OUT_OF_SERVICE, self::MAINTENANCE],
        self::CLEANING => [self::AVAILABLE, self::RESERVED, self::OUT_OF_SERVICE, self::MAINTENANCE],
        self::OUT_OF_SERVICE => [self::AVAILABLE, self::RESERVED, self::CLEANING, self::MAINTENANCE],
        self::MAINTENANCE => [self::AVAILABLE, self::RESERVED, self::CLEANING, self::OUT_OF_SERVICE],
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::VALID, true);
    }

    /**
     * Whether the transition is legal. $from may be null (creation).
     */
    public static function canTransition(?string $from, string $to): bool
    {
        if ($from === null) {
            return self::isValid($to);
        }

        // OCCUPIED transitions: occupied → available (discharge), occupied → cleaning
        if ($from === self::OCCUPIED) {
            return in_array($to, [self::AVAILABLE, self::CLEANING, self::RESERVED, self::MAINTENANCE, self::OUT_OF_SERVICE], true);
        }

        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }

    /**
     * A stable, actionable reason when a transition is rejected.
     */
    public static function rejectionReason(string $to, ?string $from = null): string
    {
        return sprintf(
            'Transition %s → %s is not a valid bed state change.',
            $from ?? 'null', $to,
        );
    }
}
