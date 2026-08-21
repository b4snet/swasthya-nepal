<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Appointment;
use App\Models\Consent;
use App\Models\Encounter;
use App\Models\FacilitySetting;
use App\Models\Teleconsult;
use App\Models\VideoSession;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 slice 24 — Telehealth (ROADMAP Phase 19, PRODUCT_REQUIREMENTS
 * §6.20): virtual consultations integrated with the same record, not a
 * separate product.
 *
 * The teleconsult is booked through the SAME schedule/queue model as OPD
 * (appointment_type = 'teleconsult'); this service then owns the virtual
 * session state machine:
 *
 *   scheduled → ready → in_progress → completed
 *             ↘ cancelled
 *             ↘ failed (connectivity failure → documented fallback_mode:
 *               phone / in_person / reschedule — never a silent drop)
 *
 * Safety guarantees (CLINICAL_SAFETY.md §7):
 *  - CONSENT GATE: a teleconsult cannot move to in_progress without the
 *    patient's ACTIVE telehealth consent (Consent::TYPE_TELEHEALTH). The
 *    consent must cover 'video' for a video session.
 *  - RECORDING POLICY: the facility's `telehealth.recording_policy`
 *    setting (disabled | consent_required | always_allowed — default
 *    disabled) plus the separate telehealth:record permission plus the
 *    patient's ACTIVE telehealth consent covering 'recording' when the
 *    policy requires it. Recording never happens implicitly.
 *  - VIDEO PRIVACY: sessions record METADATA ONLY (ref, participant,
 *    start/end, recording decision) — pixels and media never enter the
 *    database; the recording storage ref is a reference, not content.
 *  - FALLBACK: a failed video session marks the teleconsult failed with a
 *    documented fallback_mode; the consultation continues (or is
 *    rescheduled) — never silently dropped, always audited.
 *  - CAS on (status, lock_version) for every transition: two concurrent
 *    start/cancel/complete calls can never double-transition.
 *  - The shared Encounter (TYPE_TELECONSULT) is created at start and the
 *    consultation is documented/signed to the SAME standard as OPD.
 */
final class TelehealthService
{
    public const RECORDING_POLICY_DISABLED = 'disabled';

    public const RECORDING_POLICY_CONSENT_REQUIRED = 'consent_required';

    public const RECORDING_POLICY_ALWAYS_ALLOWED = 'always_allowed';

    public const RECORDING_SETTING_KEY = 'telehealth.recording_policy';

    /**
     * Create the teleconsult from a teleconsult appointment. One teleconsult
     * per appointment (partial unique) — a concurrent double-create is 409.
     */
    public function schedule(
        Appointment $appointment,
        string $staffId,
    ): Teleconsult {
        if ($appointment->appointment_type !== Appointment::TYPE_TELECONSULT) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'A teleconsult can only be scheduled from a teleconsult appointment (appointment type: '.$appointment->appointment_type.').',
                409,
            );
        }

        if ($appointment->status !== Appointment::STATUS_BOOKED) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'Only a booked appointment can be turned into a teleconsult (current status: '.$appointment->status.').',
                409,
            );
        }

        return $this->guardUnique(fn (): Teleconsult => DB::transaction(function () use ($appointment, $staffId): Teleconsult {
            return Teleconsult::query()->create([
                'tenant_id' => $appointment->tenant_id,
                'facility_id' => $appointment->facility_id,
                'appointment_id' => $appointment->getKey(),
                'patient_id' => $appointment->patient_id,
                'provider_staff_id' => $appointment->provider_staff_id,
                'status' => Teleconsult::STATUS_SCHEDULED,
                'scheduled_at' => now(),
                'starts_at' => $appointment->starts_at,
                'ends_at' => $appointment->ends_at,
                'created_by_staff_id' => $staffId,
                'updated_by_staff_id' => $staffId,
                'lock_version' => 0,
            ]);
        }));
    }

    /**
     * scheduled → ready. The patient is prepared and identity verified;
     * this is the pre-visit step (PRODUCT_REQUIREMENTS §6.20.2).
     */
    public function markReady(Teleconsult $teleconsult, string $staffId): Teleconsult
    {
        return $this->transition($teleconsult, [Teleconsult::STATUS_SCHEDULED], Teleconsult::STATUS_READY, $staffId);
    }

    /**
     * ready → in_progress. The CONSENT GATE: requires the patient's ACTIVE
     * telehealth consent covering the requested medium ('video' for a video
     * session, 'phone' for the audio fallback). Creates the shared
     * Encounter (TYPE_TELECONSULT) in the same transaction — the consult is
     * documented and signed to the SAME standard as OPD.
     */
    public function start(Teleconsult $teleconsult, string $medium, string $staffId, ?string $userId = null): Teleconsult
    {
        if (! in_array($medium, ['video', 'phone'], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The consult medium must be video or phone.', 422);
        }

        $this->assertActiveConsent($teleconsult, $medium);

        return DB::transaction(function () use ($teleconsult, $staffId, $userId): Teleconsult {
            $updated = $this->transitionInner(
                $teleconsult,
                [Teleconsult::STATUS_READY],
                Teleconsult::STATUS_IN_PROGRESS,
                $staffId,
            );

            // The shared clinical record — SAME discipline as OPD. The
            // encounter is bound to the appointment (partial unique on
            // appointment_id), so a concurrent start can never create two.
            Encounter::query()->create([
                'tenant_id' => $updated->tenant_id,
                'facility_id' => $updated->facility_id,
                'patient_id' => $updated->patient_id,
                'appointment_id' => $updated->appointment_id,
                'provider_staff_id' => $updated->provider_staff_id,
                'type' => Encounter::TYPE_TELECONSULT,
                'status' => Encounter::STATUS_OPEN,
                'started_at' => now(),
                'lock_version' => 0,
                'created_by' => $userId,
            ]);

            return $updated;
        });
    }

    /**
     * Open a video session for an in-progress teleconsult. Metadata only.
     * `recordingRequested` is the EXPLICIT operator decision — the actual
     * recording start is a separate, permission+consent+policy-gated call.
     */
    public function openVideoSession(
        Teleconsult $teleconsult,
        string $participantType,
        bool $recordingRequested,
        ?string $providerSessionRef,
        string $staffId,
    ): VideoSession {
        $this->assertActiveConsent($teleconsult, 'video');

        if ($teleconsult->status !== Teleconsult::STATUS_IN_PROGRESS) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'A video session can only be opened for an in-progress teleconsult (current status: '.$teleconsult->status.').',
                409,
            );
        }

        return DB::transaction(function () use ($teleconsult, $participantType, $recordingRequested, $providerSessionRef, $staffId): VideoSession {
            return VideoSession::query()->create([
                'tenant_id' => $teleconsult->tenant_id,
                'facility_id' => $teleconsult->facility_id,
                'teleconsult_id' => $teleconsult->getKey(),
                'status' => VideoSession::STATUS_ACTIVE,
                'started_at' => now(),
                'ended_at' => null,
                'provider_session_ref' => $providerSessionRef,
                'participant_type' => $participantType,
                'recording_requested' => $recordingRequested,
                'recording_consent_verified' => false,
                'recording_started_at' => null,
                'recording_ended_at' => null,
                'recording_storage_ref' => null,
                'failure_reason' => null,
                'created_by_staff_id' => $staffId,
                'lock_version' => 0,
            ]);
        });
    }

    /**
     * active → ended. Normal session close.
     */
    public function endVideoSession(VideoSession $session, string $staffId): VideoSession
    {
        $this->assertSessionActive($session);

        $affected = VideoSession::query()
            ->whereKey($session->getKey())
            ->where('status', VideoSession::STATUS_ACTIVE)
            ->where('lock_version', $session->lock_version)
            ->update([
                'status' => VideoSession::STATUS_ENDED,
                'ended_at' => now(),
                'recording_ended_at' => DB::raw('coalesce(recording_ended_at, now())'),
                'lock_version' => $session->lock_version + 1,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The video session state changed concurrently.', 409);
        }

        return $session->refresh();
    }

    /**
     * active → failed. Connectivity failure: the teleconsult records its
     * documented fallback (phone / in_person / reschedule) — never a silent
     * drop (CLINICAL_SAFETY.md §7). The session carries the failure reason.
     */
    public function failVideoSession(
        VideoSession $session,
        string $fallbackMode,
        ?string $fallbackReason,
        string $staffId,
    ): array {
        $this->assertSessionActive($session);

        if (! in_array($fallbackMode, [
            Teleconsult::FALLBACK_PHONE,
            Teleconsult::FALLBACK_IN_PERSON,
            Teleconsult::FALLBACK_RESCHEDULE,
        ], true)) {
            throw new ApiException(ErrorCodes::VALIDATION_ERROR, 'The fallback mode is not supported.', 422);
        }

        /** @var Teleconsult $teleconsult */
        $teleconsult = $session->teleconsult()->firstOrFail();

        return DB::transaction(function () use ($session, $fallbackMode, $fallbackReason, $staffId, $teleconsult): array {
            $sessionAffected = VideoSession::query()
                ->whereKey($session->getKey())
                ->where('status', VideoSession::STATUS_ACTIVE)
                ->where('lock_version', $session->lock_version)
                ->update([
                    'status' => VideoSession::STATUS_FAILED,
                    'ended_at' => now(),
                    'failure_reason' => $fallbackReason,
                    'lock_version' => $session->lock_version + 1,
                ]);

            if ($sessionAffected !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The video session state changed concurrently.', 409);
            }

            // The teleconsult is marked failed with the documented fallback.
            // in_progress → failed (or ready → failed when the session never
            // began); the fallback mode + reason are clinical facts.
            $teleconsultAffected = Teleconsult::query()
                ->whereKey($teleconsult->getKey())
                ->whereIn('status', [Teleconsult::STATUS_IN_PROGRESS, Teleconsult::STATUS_READY])
                ->where('lock_version', $teleconsult->lock_version)
                ->update([
                    'status' => Teleconsult::STATUS_FAILED,
                    'fallback_mode' => $fallbackMode,
                    'fallback_reason' => $fallbackReason,
                    'lock_version' => $teleconsult->lock_version + 1,
                    'updated_by_staff_id' => $staffId,
                ]);

            if ($teleconsultAffected !== 1) {
                throw new ApiException(ErrorCodes::CONFLICT, 'The teleconsult state changed concurrently.', 409);
            }

            return [$session->refresh(), $teleconsult->refresh()];
        });
    }

    /**
     * Start a recording: EXPLICIT operator decision + the separate
     * telehealth:record permission (checked by the controller) + the
     * facility recording policy + the patient's ACTIVE telehealth consent
     * covering 'recording' when the policy requires it. Returns the session
     * and whether the policy allowed recording.
     *
     * @return array{0: VideoSession, 1: bool}
     */
    public function startRecording(VideoSession $session, string $storageRef, string $staffId): array
    {
        $this->assertSessionActive($session);

        $policy = $this->recordingPolicy((string) $session->tenant_id, (string) $session->facility_id);

        if ($policy === self::RECORDING_POLICY_DISABLED) {
            return [$session, false];
        }

        $consentVerified = $this->hasActiveConsentFor(
            (string) $session->tenant_id,
            (string) $session->facility_id,
            (string) $session->teleconsult()->firstOrFail()->patient_id,
            'recording',
        );

        if ($policy === self::RECORDING_POLICY_CONSENT_REQUIRED && ! $consentVerified) {
            return [$session, false];
        }

        $affected = VideoSession::query()
            ->whereKey($session->getKey())
            ->where('status', VideoSession::STATUS_ACTIVE)
            ->where('lock_version', $session->lock_version)
            ->update([
                'recording_requested' => true,
                'recording_consent_verified' => $consentVerified,
                'recording_started_at' => now(),
                'recording_ended_at' => null,
                'recording_storage_ref' => $storageRef,
                'lock_version' => $session->lock_version + 1,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The video session state changed concurrently.', 409);
        }

        return [$session->refresh(), true];
    }

    /**
     * Stop a running recording (idempotent: ending an ended recording is a
     * no-op success — the recording state is already final).
     */
    public function stopRecording(VideoSession $session, string $staffId): VideoSession
    {
        if ($session->recording_started_at === null) {
            throw new ApiException(ErrorCodes::CONFLICT, 'No recording is active on this session.', 409);
        }

        if ($session->recording_ended_at !== null) {
            return $session;
        }

        $affected = VideoSession::query()
            ->whereKey($session->getKey())
            ->whereNull('recording_ended_at')
            ->where('lock_version', $session->lock_version)
            ->update([
                'recording_ended_at' => now(),
                'lock_version' => $session->lock_version + 1,
            ]);

        if ($affected !== 1) {
            throw new ApiException(ErrorCodes::CONFLICT, 'The video session state changed concurrently.', 409);
        }

        return $session->refresh();
    }

    /**
     * in_progress → completed. The encounter must be SIGNED first — the
     * consultation meets the same documentation/sign-off standard as OPD.
     */
    public function complete(Teleconsult $teleconsult, string $staffId): Teleconsult
    {
        /** @var Encounter|null $encounter */
        $encounter = Encounter::query()->where('appointment_id', $teleconsult->appointment_id)->first();

        if ($encounter === null || $encounter->status !== Encounter::STATUS_SIGNED) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'A teleconsult can only be completed after its encounter is signed (the same sign-off standard as OPD).',
                409,
            );
        }

        return $this->transition($teleconsult, [Teleconsult::STATUS_IN_PROGRESS], Teleconsult::STATUS_COMPLETED, $staffId);
    }

    /**
     * scheduled | ready → cancelled. Terminal, audited.
     */
    public function cancel(Teleconsult $teleconsult, string $staffId): Teleconsult
    {
        return $this->transition(
            $teleconsult,
            [Teleconsult::STATUS_SCHEDULED, Teleconsult::STATUS_READY],
            Teleconsult::STATUS_CANCELLED,
            $staffId,
        );
    }

    /**
     * Get the waiting room: teleconsults in scheduled/ready status for
     * a facility, ordered by scheduled time.
     *
     * @return Collection<int, Teleconsult>
     */
    public function waitingRoom(string $tenantId, string $facilityId): \Illuminate\Support\Collection
    {
        return Teleconsult::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereIn('status', [Teleconsult::STATUS_SCHEDULED, Teleconsult::STATUS_READY])
            ->with(['patient:id,full_name,mrn', 'provider:id,full_name'])
            ->orderBy('scheduled_at')
            ->get();
    }

    /**
     * Get teleconsults for a specific patient (patient portal view).
     *
     * @return Collection<int, Teleconsult>
     */
    public function patientTeleconsults(string $tenantId, string $patientId): \Illuminate\Support\Collection
    {
        return Teleconsult::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->with(['provider:id,full_name'])
            ->orderByDesc('scheduled_at')
            ->limit(20)
            ->get();
    }

    /**
     * The facility's recording policy (default disabled — recording is
     * NEVER implicit).
     */
    public function recordingPolicy(string $tenantId, string $facilityId): string
    {
        /** @var FacilitySetting|null $setting */
        $setting = FacilitySetting::query()
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('key', self::RECORDING_SETTING_KEY)
            ->first();

        if ($setting === null) {
            return self::RECORDING_POLICY_DISABLED;
        }

        $policy = is_string($setting->value) ? $setting->value : (string) ($setting->value['policy'] ?? '');

        if (! in_array($policy, [
            self::RECORDING_POLICY_DISABLED,
            self::RECORDING_POLICY_CONSENT_REQUIRED,
            self::RECORDING_POLICY_ALWAYS_ALLOWED,
        ], true)) {
            return self::RECORDING_POLICY_DISABLED;
        }

        return $policy;
    }

    /**
     * The patient's ACTIVE telehealth consent covering `medium` must exist
     * (CLINICAL_SAFETY.md §7 — the consent gate).
     */
    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): T  $create
     * @return T
     */
    private function guardUnique(callable $create)
    {
        try {
            return $create();
        } catch (QueryException $e) {
            $pdo = $e->getPrevious();
            if ($pdo instanceof \PDOException && str_starts_with((string) $pdo->getCode(), '23505')) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'A teleconsult for this appointment already exists.',
                    409,
                );
            }

            throw $e;
        }
    }

    private function assertActiveConsent(Teleconsult $teleconsult, string $medium): void
    {
        $consented = $this->hasActiveConsentFor(
            (string) $teleconsult->tenant_id,
            (string) $teleconsult->facility_id,
            (string) $teleconsult->patient_id,
            $medium,
        );

        if (! $consented) {
            throw new ApiException(
                ErrorCodes::FORBIDDEN,
                'An active telehealth consent covering '.$medium.' is required for this consultation.',
                403,
            );
        }
    }

    private function hasActiveConsentFor(string $tenantId, string $facilityId, string $patientId, string $scope): bool
    {
        return Consent::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->where('consent_type', Consent::TYPE_TELEHEALTH)
            ->where('status', Consent::STATUS_ACTIVE)
            ->whereJsonContains('scope', $scope)
            ->exists();
    }

    private function assertSessionActive(VideoSession $session): void
    {
        if ($session->status !== VideoSession::STATUS_ACTIVE) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The video session is not active (current status: '.$session->status.').',
                409,
            );
        }
    }

    private function transition(
        Teleconsult $teleconsult,
        array $from,
        string $to,
        string $staffId,
    ): Teleconsult {
        return DB::transaction(fn (): Teleconsult => $this->transitionInner($teleconsult, $from, $to, $staffId));
    }

    private function transitionInner(
        Teleconsult $teleconsult,
        array $from,
        string $to,
        string $staffId,
    ): Teleconsult {
        $affected = Teleconsult::query()
            ->whereKey($teleconsult->getKey())
            ->whereIn('status', $from)
            ->where('lock_version', $teleconsult->lock_version)
            ->update([
                'status' => $to,
                'lock_version' => $teleconsult->lock_version + 1,
                'updated_by_staff_id' => $staffId,
            ]);

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The teleconsult state changed concurrently (expected '.implode('|', $from).', got '.$teleconsult->status.').',
                409,
            );
        }

        return $teleconsult->refresh();
    }
}
