<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telehealth\FailVideoSessionRequest;
use App\Http\Requests\Telehealth\OpenVideoSessionRequest;
use App\Http\Requests\Telehealth\RecordingRequest;
use App\Http\Requests\Telehealth\ScheduleTeleconsultRequest;
use App\Http\Requests\Telehealth\StartTeleconsultRequest;
use App\Models\Appointment;
use App\Models\Teleconsult;
use App\Models\VideoSession;
use App\Services\TelehealthService;
use App\Support\AccessCheck;
use App\Support\AuditLogger;
use App\Support\Envelope;
use App\Support\ErrorCodes;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telehealth (ROADMAP Phase 19, PRODUCT_REQUIREMENTS §6.20): virtual
 * consultations integrated with the same record, not a separate product.
 *
 * Surfaces:
 *  - SCHEDULE (authorize:telehealth:schedule): turn a teleconsult
 *    appointment into a teleconsult (front desk / schedulers).
 *  - CONDUCT (authorize:telehealth:conduct): the provider runs the visit —
 *    mark ready, start (consent gate), open/end video sessions, document
 *    the fallback on connectivity failure, complete the signed consult.
 *  - RECORD (authorize:telehealth:record — separate + restricted): start /
 *    stop an EXPLICIT recording; the facility recording policy and the
 *    patient's consent must also allow it (never implicit).
 *
 * The shared Encounter (TYPE_TELECONSULT) is created at start; notes,
 * diagnoses, prescriptions, and sign-off use the SAME endpoints as OPD —
 * the consultation meets the same documentation/sign-off standard.
 * Audit payloads carry facts only (ids, statuses, mediums, timestamps) —
 * never clinical content or PHI.
 */
final class TelehealthController extends Controller
{
    public function __construct(
        private readonly TelehealthService $telehealth,
        private readonly AuditLogger $audit,
    ) {}

    // ───────────────────────────── Schedule ──────────────────────────────

    /**
     * POST telehealth/schedule — create the teleconsult from a booked
     * teleconsult appointment.
     */
    public function schedule(ScheduleTeleconsultRequest $request): JsonResponse
    {
        $context = TenantContext::current();
        $staffId = $this->currentStaffId($request);

        /** @var Appointment|null $appointment */
        $appointment = Appointment::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->find($request->validated('appointmentId'));

        if ($appointment === null) {
            throw new ApiException(ErrorCodes::NOT_FOUND, 'Appointment not found.', 404);
        }

        AccessCheck::scoped($appointment, write: true);

        $teleconsult = $this->telehealth->schedule($appointment, (string) $staffId);

        $this->audit->record(
            'telehealth.scheduled',
            'teleconsult',
            $teleconsult->getKey(),
            ['appointmentId' => $appointment->getKey(), 'patientId' => $teleconsult->patient_id, 'providerStaffId' => $teleconsult->provider_staff_id],
            $request,
        );

        return Envelope::success(data: self::presentTeleconsult($teleconsult), status: 201, request: $request);
    }

    /**
     * GET telehealth/teleconsults — the facility's teleconsult list.
     */
    public function index(Request $request): JsonResponse
    {
        $context = TenantContext::current();

        $teleconsults = Teleconsult::query()
            ->where('tenant_id', (string) $context->tenantId())
            ->when($context->facilityId() !== null, fn ($q) => $q->where('facility_id', $context->facilityId()))
            ->with(['patient:id,full_name,mrn', 'provider:id,full_name'])
            ->orderByDesc('scheduled_at')
            ->limit(200)
            ->get()
            ->map(fn (Teleconsult $t): array => self::presentTeleconsult($t, includeNames: true))
            ->values();

        return Envelope::success(data: $teleconsults, request: $request);
    }

    /**
     * GET telehealth/teleconsults/{teleconsult} — one consult.
     */
    public function show(Request $request, Teleconsult $teleconsult): JsonResponse
    {
        AccessCheck::scoped($teleconsult, write: false);

        $teleconsult->load(['patient:id,full_name,mrn', 'provider:id,full_name', 'videoSessions']);

        return Envelope::success(data: self::presentTeleconsult($teleconsult, includeNames: true, includeSessions: true), request: $request);
    }

    // ───────────────────────────── Conduct ───────────────────────────────

    /**
     * POST telehealth/teleconsults/{teleconsult}/ready — pre-visit complete
     * (patient prepared + identity verified).
     */
    public function markReady(Request $request, Teleconsult $teleconsult): JsonResponse
    {
        AccessCheck::scoped($teleconsult, write: true);
        $staffId = $this->currentStaffId($request);

        $updated = $this->telehealth->markReady($teleconsult, (string) $staffId);

        $this->audit->record('telehealth.ready', 'teleconsult', $updated->getKey(), ['status' => $updated->status], $request);

        return Envelope::success(data: self::presentTeleconsult($updated), request: $request);
    }

    /**
     * POST telehealth/teleconsults/{teleconsult}/start — the consent gate,
     * then ready → in_progress with the shared Encounter created.
     */
    public function start(StartTeleconsultRequest $request, Teleconsult $teleconsult): JsonResponse
    {
        AccessCheck::scoped($teleconsult, write: true);
        $context = TenantContext::current();
        $staffId = $this->currentStaffId($request);

        $updated = $this->telehealth->start(
            $teleconsult,
            (string) $request->validated('medium', 'video'),
            (string) $staffId,
            $context->user?->getKey(),
        );

        $this->audit->record(
            'telehealth.started',
            'teleconsult',
            $updated->getKey(),
            ['medium' => $request->validated('medium', 'video'), 'status' => $updated->status],
            $request,
        );

        return Envelope::success(data: self::presentTeleconsult($updated), status: 201, request: $request);
    }

    /**
     * POST telehealth/teleconsults/{teleconsult}/video-sessions — open a
     * secure video session (metadata only).
     */
    public function openVideoSession(OpenVideoSessionRequest $request, Teleconsult $teleconsult): JsonResponse
    {
        AccessCheck::scoped($teleconsult, write: true);
        $staffId = $this->currentStaffId($request);

        $session = $this->telehealth->openVideoSession(
            $teleconsult,
            (string) $request->validated('participantType', VideoSession::PARTICIPANT_PROVIDER),
            (bool) $request->validated('recordingRequested', false),
            $request->validated('providerSessionRef'),
            (string) $staffId,
        );

        $this->audit->record(
            'telehealth.video_opened',
            'video_session',
            $session->getKey(),
            ['teleconsultId' => $teleconsult->getKey(), 'participantType' => $session->participant_type, 'recordingRequested' => $session->recording_requested],
            $request,
        );

        return Envelope::success(data: self::presentSession($session), status: 201, request: $request);
    }

    /**
     * POST telehealth/video-sessions/{videoSession}/end — normal close.
     */
    public function endVideoSession(Request $request, VideoSession $videoSession): JsonResponse
    {
        AccessCheck::scoped($videoSession, write: true);
        $staffId = $this->currentStaffId($request);

        $ended = $this->telehealth->endVideoSession($videoSession, (string) $staffId);

        $this->audit->record('telehealth.video_ended', 'video_session', $ended->getKey(), ['teleconsultId' => $videoSession->teleconsult_id, 'status' => $ended->status], $request);

        return Envelope::success(data: self::presentSession($ended), request: $request);
    }

    /**
     * POST telehealth/video-sessions/{videoSession}/fail — connectivity
     * failure: session failed + teleconsult fallback documented (audited).
     */
    public function failVideoSession(FailVideoSessionRequest $request, VideoSession $videoSession): JsonResponse
    {
        AccessCheck::scoped($videoSession, write: true);
        $staffId = $this->currentStaffId($request);

        [$failedSession, $teleconsult] = $this->telehealth->failVideoSession(
            $videoSession,
            (string) $request->validated('fallbackMode'),
            $request->validated('fallbackReason'),
            (string) $staffId,
        );

        $this->audit->record(
            'telehealth.video_failed',
            'video_session',
            $failedSession->getKey(),
            ['teleconsultId' => $teleconsult->getKey(), 'fallbackMode' => $teleconsult->fallback_mode, 'status' => $failedSession->status],
            $request,
        );

        return Envelope::success(data: [
            'session' => self::presentSession($failedSession),
            'teleconsult' => self::presentTeleconsult($teleconsult),
        ], request: $request);
    }

    // ───────────────────────────── Recording ─────────────────────────────

    /**
     * POST telehealth/video-sessions/{videoSession}/recording — start/stop
     * an EXPLICIT recording (telehealth:record gate + policy + consent).
     * A policy refusal returns 200 with `recordingAllowed: false` — the
     * operator sees the decision, the consult continues unaffected.
     */
    public function recording(RecordingRequest $request, VideoSession $videoSession): JsonResponse
    {
        AccessCheck::scoped($videoSession, write: true);
        $staffId = $this->currentStaffId($request);

        $action = (string) $request->validated('action');

        if ($action === 'stop') {
            // Idempotent: a repeat stop (already ended) is a no-op success —
            // only an actual transition is audited.
            $wasRunning = $videoSession->recording_ended_at === null;
            $updated = $this->telehealth->stopRecording($videoSession, (string) $staffId);

            if ($wasRunning) {
                $this->audit->record('telehealth.recording_stopped', 'video_session', $updated->getKey(), ['teleconsultId' => $videoSession->teleconsult_id], $request);
            }

            return Envelope::success(data: ['recordingAllowed' => true, 'session' => self::presentSession($updated)], request: $request);
        }

        [$updated, $allowed] = $this->telehealth->startRecording(
            $videoSession,
            (string) $request->validated('storageRef'),
            (string) $staffId,
        );

        $this->audit->record(
            $allowed ? 'telehealth.recording_started' : 'telehealth.recording_refused',
            'video_session',
            $updated->getKey(),
            ['teleconsultId' => $videoSession->teleconsult_id, 'recordingAllowed' => $allowed],
            $request,
        );

        return Envelope::success(data: ['recordingAllowed' => $allowed, 'session' => self::presentSession($updated)], request: $request);
    }

    // ───────────────────────────── Complete ──────────────────────────────

    /**
     * POST telehealth/teleconsults/{teleconsult}/complete — in_progress →
     * completed, only after the shared encounter is SIGNED (same standard
     * as OPD).
     */
    public function complete(Request $request, Teleconsult $teleconsult): JsonResponse
    {
        AccessCheck::scoped($teleconsult, write: true);
        $staffId = $this->currentStaffId($request);

        $completed = $this->telehealth->complete($teleconsult, (string) $staffId);

        $this->audit->record('telehealth.completed', 'teleconsult', $completed->getKey(), ['status' => $completed->status], $request);

        return Envelope::success(data: self::presentTeleconsult($completed), request: $request);
    }

    /**
     * POST telehealth/teleconsults/{teleconsult}/cancel — scheduled | ready
     * → cancelled.
     */
    public function cancel(Request $request, Teleconsult $teleconsult): JsonResponse
    {
        AccessCheck::scoped($teleconsult, write: true);
        $staffId = $this->currentStaffId($request);

        $cancelled = $this->telehealth->cancel($teleconsult, (string) $staffId);

        $this->audit->record('telehealth.cancelled', 'teleconsult', $cancelled->getKey(), ['status' => $cancelled->status], $request);

        return Envelope::success(data: self::presentTeleconsult($cancelled), request: $request);
    }

    // ───────────────────────────── Helpers ───────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function presentTeleconsult(Teleconsult $t, bool $includeNames = false, bool $includeSessions = false): array
    {
        return array_merge([
            'id' => $t->getKey(),
            'appointmentId' => $t->appointment_id,
            'patientId' => $t->patient_id,
            'providerStaffId' => $t->provider_staff_id,
            'status' => $t->status,
            'scheduledAt' => $t->scheduled_at->toIso8601String(),
            'startsAt' => $t->starts_at?->toIso8601String(),
            'endsAt' => $t->ends_at?->toIso8601String(),
            'fallbackMode' => $t->fallback_mode,
            'lockVersion' => $t->lock_version,
        ], $includeNames ? [
            'patient' => $t->patient ? ['id' => $t->patient->getKey(), 'fullName' => $t->patient->full_name, 'mrn' => $t->patient->mrn] : null,
            'provider' => $t->provider ? ['id' => $t->provider->getKey(), 'fullName' => $t->provider->full_name] : null,
        ] : [], $includeSessions ? [
            'videoSessions' => $t->videoSessions->map(fn (VideoSession $s): array => self::presentSession($s))->values(),
        ] : []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentSession(VideoSession $session): array
    {
        return [
            'id' => $session->getKey(),
            'teleconsultId' => $session->teleconsult_id,
            'status' => $session->status,
            'startedAt' => $session->started_at?->toIso8601String(),
            'endedAt' => $session->ended_at?->toIso8601String(),
            'participantType' => $session->participant_type,
            'recordingRequested' => $session->recording_requested,
            'recordingConsentVerified' => $session->recording_consent_verified,
            'recordingStartedAt' => $session->recording_started_at?->toIso8601String(),
            'recordingEndedAt' => $session->recording_ended_at?->toIso8601String(),
            'hasRecordingStorageRef' => $session->recording_storage_ref !== null,
            'lockVersion' => $session->lock_version,
        ];
    }

    private function currentStaffId(Request $request): ?string
    {
        $context = TenantContext::current();

        return $context->user?->staff()
            ->where('tenant_id', (string) $context->tenantId())
            ->where('facility_id', (string) $context->facilityId())
            ->where('status', '!=', 'departed')
            ->first()?->getKey();
    }
}
