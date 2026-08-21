<?php

namespace App\Services\Video;

/**
 * Dummy video adapter (Phase 83): returns stub data for development and
 * testing. Used when no real video provider is configured.
 *
 * This adapter documents the integration contract. Replace with a real
 * adapter (Daily.co, Twilio, etc.) in production deployments.
 *
 * IMPORTANT: Do NOT claim live video integration when this adapter is
 * active. The application must log a warning when the dummy adapter is
 * in use.
 */
final class DummyAdapter implements VideoAdapter
{
    public function createRoom(string $teleconsultId, string $tenantId, array $options = []): array
    {
        $roomId = 'room_'.bin2hex(random_bytes(12));

        return [
            'roomId' => $roomId,
            'roomUrl' => "https://video.example.com/room/{$roomId}",
            'expiresAt' => now()->addHours(4)->toIso8601String(),
        ];
    }

    public function generateParticipantToken(string $roomId, string $participantName, string $role): array
    {
        $token = 'tok_'.bin2hex(random_bytes(16));

        return [
            'token' => $token,
            'joinUrl' => "https://video.example.com/join/{$roomId}?token={$token}",
            'expiresAt' => now()->addHours(2)->toIso8601String(),
        ];
    }

    public function endRoom(string $roomId): bool
    {
        return true;
    }

    public function getRoomStatus(string $roomId): array
    {
        return [
            'status' => 'active',
            'participantCount' => 0,
            'participants' => [],
        ];
    }

    public function startRecording(string $roomId): array
    {
        return [
            'recordingId' => 'rec_'.bin2hex(random_bytes(8)),
            'status' => 'started',
        ];
    }

    public function stopRecording(string $roomId, string $recordingId): array
    {
        return [
            'status' => 'stopped',
            'storageRef' => null,
        ];
    }
}
