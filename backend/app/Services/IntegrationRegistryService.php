<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\EgressDestination;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The integration registry (DATABASE.md §3.42, INTEROPERABILITY.md §13–14):
 * what is connected and its MEASURED status — recorded by status checks,
 * never asserted. Re-registering the same (tenant, type, provider) is a
 * 409; status changes are CAS-guarded (concurrent writers lose with 409);
 * the kill-switch is an audited, independently togglable state; the message
 * log is append-only with a bounded CAS retry budget (exceeding the budget
 * quarantines — INTEROPERABILITY.md §7); egress is refused unless the
 * tenant's allowlist admits the destination (SECURITY.md §22).
 */
final class IntegrationRegistryService
{
    public const RETRY_BUDGET = 5;

    /**
     * @param  array<string, mixed>  $data
     */
    public function register(array $data, string $staffId): Integration
    {
        $this->assertType($data['type'] ?? null);

        return $this->guardUnique(fn (): Integration => Integration::query()->create([
            'tenant_id' => (string) $data['tenant_id'],
            'type' => (string) $data['type'],
            'provider' => (string) $data['provider'],
            'config_encrypted' => $data['config_encrypted'] ?? null,
            'status' => Integration::STATUS_CONFIGURED,
            'owner_staff_id' => $data['owner_staff_id'] ?? null,
            'purpose' => (string) $data['purpose'],
            'contract_version' => (string) $data['contract_version'],
            'standards_version' => $data['standards_version'] ?? null,
            'mapping_version' => $data['mapping_version'] ?? null,
            'kill_switched' => false,
            'lock_version' => 0,
            'created_by_staff_id' => $staffId,
            'updated_by_staff_id' => $staffId,
        ]));
    }

    /**
     * Record a MEASURED status: the caller observed the integration (health
     * probe / manual check) and reports the result. CAS on lock_version — a
     * concurrent writer affects zero rows → 409 (the registry never
     * silently overwrites a newer measurement).
     *
     * @param  array<string, mixed>|null  $health
     */
    public function recordStatusCheck(Integration $integration, string $status, ?array $health, string $staffId): Integration
    {
        $this->assertStatus($status);

        $affected = Integration::query()
            ->whereKey($integration->getKey())
            ->where('lock_version', $integration->lock_version)
            ->update([
                'status' => $status,
                'health' => $health,
                'last_checked_at' => now(),
                'lock_version' => $integration->lock_version + 1,
                'updated_by_staff_id' => $staffId,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The integration status changed concurrently.', 409);
        }

        return $integration->refresh();
    }

    public function setKillSwitch(Integration $integration, bool $on, string $staffId): Integration
    {
        $affected = Integration::query()
            ->whereKey($integration->getKey())
            ->where('lock_version', $integration->lock_version)
            ->update([
                'kill_switched' => $on,
                'lock_version' => $integration->lock_version + 1,
                'updated_by_staff_id' => $staffId,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The integration state changed concurrently.', 409);
        }

        return $integration->refresh();
    }

    /**
     * Append an exchange to the message log (facts only — payloads live
     * here, never in operational logs). An outbound exchange carrying
     * patient data MUST record its consent basis (INTEROPERABILITY.md §10).
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordEvent(
        Integration $integration,
        string $direction,
        string $messageType,
        array $payload,
        ?string $consentBasis = null,
        ?string $mappingVersion = null,
    ): IntegrationEvent {
        $this->assertDirection($direction);

        return IntegrationEvent::query()->create([
            'tenant_id' => $integration->tenant_id,
            'integration_id' => $integration->getKey(),
            'direction' => $direction,
            'message_type' => $messageType,
            'correlation_id' => (string) Str::uuid(),
            'consent_basis' => $consentBasis,
            'payload' => $payload,
            'status' => IntegrationEvent::STATUS_QUEUED,
            'attempts' => 0,
            'error' => null,
            'mapping_version' => $mappingVersion,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Bounded retry: CAS-increments attempts within the budget; an attempt
     * past the budget quarantines the message (INTEROPERABILITY.md §7 —
     * silent message death is prohibited; quarantine is the visible fact).
     */
    public function markRetry(IntegrationEvent $event, ?string $error = null, int $budget = self::RETRY_BUDGET): IntegrationEvent
    {
        $next = $event->attempts + 1;
        $quarantined = $next > $budget;

        $affected = IntegrationEvent::query()
            ->whereKey($event->getKey())
            ->where('attempts', $event->attempts)
            ->update([
                'attempts' => $next,
                'status' => $quarantined ? IntegrationEvent::STATUS_QUARANTINED : IntegrationEvent::STATUS_RETRYING,
                'error' => $error,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The message attempt state changed concurrently.', 409);
        }

        return $event->refresh();
    }

    /**
     * The egress guard (SECURITY.md §22, INTEROPERABILITY.md §11): an
     * outbound call may only reach a destination the tenant has allowlisted
     * as ACTIVE. A missing/disabled entry is refused — even if a provider
     * credential exists.
     */
    public function assertEgressAllowed(string $tenantId, string $host, int $port): void
    {
        $allowed = EgressDestination::query()
            ->where('tenant_id', $tenantId)
            ->where('host', $host)
            ->where('port', $port)
            ->where('is_active', true)
            ->exists();

        if (! $allowed) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'The outbound destination is not on the tenant egress allowlist.',
                403,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addEgressDestination(array $data, string $staffId): EgressDestination
    {
        return $this->guardUnique(fn (): EgressDestination => EgressDestination::query()->create([
            'tenant_id' => (string) $data['tenant_id'],
            'integration_id' => $data['integration_id'] ?? null,
            'host' => (string) $data['host'],
            'port' => (int) $data['port'],
            'purpose' => (string) $data['purpose'],
            'is_active' => true,
            'created_by_staff_id' => $staffId,
        ]));
    }

    private function assertType(mixed $type): void
    {
        $allowed = [
            Integration::TYPE_PAYMENT, Integration::TYPE_SMS, Integration::TYPE_EMAIL,
            Integration::TYPE_LAB, Integration::TYPE_PACS, Integration::TYPE_FHIR,
            Integration::TYPE_HL7, Integration::TYPE_DICOM, Integration::TYPE_NATIONAL,
        ];

        if (! in_array($type, $allowed, true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The integration type is not supported.', 422);
        }
    }

    private function assertStatus(string $status): void
    {
        $allowed = [
            Integration::STATUS_CONFIGURED, Integration::STATUS_ACTIVE,
            Integration::STATUS_DEGRADED, Integration::STATUS_DISABLED,
        ];

        if (! in_array($status, $allowed, true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The integration status is not valid.', 422);
        }
    }

    private function assertDirection(string $direction): void
    {
        if (! in_array($direction, [IntegrationEvent::DIRECTION_INBOUND, IntegrationEvent::DIRECTION_OUTBOUND], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The event direction is not valid.', 422);
        }
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function guardUnique(callable $create)
    {
        try {
            return DB::transaction($create);
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();
            if ($pdo instanceof \PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'An integration or allowlist entry for this tenant already exists.',
                    409,
                );
            }

            throw $e;
        }
    }
}
