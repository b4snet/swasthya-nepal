<?php

namespace App\Services\Video;

/**
 * External video provider adapter interface (Phase 83).
 *
 * This is the integration boundary for video consultation providers
 * (Daily.co, Twilio Video, Whereby, Jitsi, etc.). The application
 * interacts with video infrastructure ONLY through this interface.
 *
 * No provider secrets are stored in the application database. Credentials
 * are managed by the deployment environment and injected at runtime.
 *
 * Implementations:
 * - DummyAdapter: always returns stub data; used when no video provider
 *   is configured. Documents the integration contract.
 * - Real adapters (DayliAdapter, TwilioAdapter, etc.) are external
 *   dependencies installed per deployment.
 */
interface VideoAdapter
{
    /**
     * Create a video room/session for a teleconsult.
     *
     * @param  string  $teleconsultId  The teleconsult identifier
     * @param  string  $tenantId  The tenant identifier
     * @param  array{maxParticipants?: int, startRecording?: bool}  $options
     * @return array{roomId: string, roomUrl: string, expiresAt: string}
     */
    public function createRoom(string $teleconsultId, string $tenantId, array $options = []): array;

    /**
     * Generate a participant token/link for joining a room.
     *
     * @param  string  $role  'provider' | 'patient'
     * @return array{token: string, joinUrl: string, expiresAt: string}
     */
    public function generateParticipantToken(string $roomId, string $participantName, string $role): array;

    /**
     * End/close a video room.
     *
     * @return bool Whether the room was successfully closed
     */
    public function endRoom(string $roomId): bool;

    /**
     * Check room/participant status.
     *
     * @return array{status: string, participantCount: int, participants: list<array{name: string, role: string, joinedAt: string}>}
     */
    public function getRoomStatus(string $roomId): array;

    /**
     * Start recording in a room.
     *
     * @return array{recordingId: string, status: string}
     */
    public function startRecording(string $roomId): array;

    /**
     * Stop recording in a room.
     *
     * @return array{status: string, storageRef: string|null}
     */
    public function stopRecording(string $roomId, string $recordingId): array;
}
