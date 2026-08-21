<?php

namespace Tests\Feature;

use App\Models\PortalInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Portal activation tests (Phase 82): invitation lifecycle, token
 * validation, and cross-patient isolation.
 */
class PortalActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_statuses(): void
    {
        $this->assertEquals('pending', PortalInvitation::STATUS_PENDING);
        $this->assertEquals('accepted', PortalInvitation::STATUS_ACCEPTED);
        $this->assertEquals('expired', PortalInvitation::STATUS_EXPIRED);
        $this->assertEquals('revoked', PortalInvitation::STATUS_REVOKED);
    }

    public function test_invitation_expiry_hours(): void
    {
        $this->assertEquals(72, PortalInvitation::TOKEN_EXPIRY_HOURS);
    }

    public function test_create_invitation(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1',
            'facility-1',
            'account-1',
            'patient-1',
            'test@example.com',
            '+9779841234567',
            'staff-1',
        );

        $this->assertNotNull($invitation->getKey());
        $this->assertEquals('pending', $invitation->status);
        $this->assertEquals('tenant-1', $invitation->tenant_id);
        $this->assertEquals('patient-1', $invitation->patient_id);
        $this->assertEquals('test@example.com', $invitation->email);
        $this->assertEquals('+9779841234567', $invitation->phone);
        $this->assertNotNull($invitation->invitation_token);
        $this->assertGreaterThan(50, strlen($invitation->invitation_token));
        $this->assertNotNull($invitation->expires_at);
        $this->assertTrue($invitation->expires_at->isFuture());
    }

    public function test_invitation_is_valid_when_pending_and_not_expired(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        $this->assertTrue($invitation->isValid());
    }

    public function test_invitation_is_invalid_when_accepted(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        $invitation->markAccepted();
        $invitation->refresh();

        $this->assertFalse($invitation->isValid());
    }

    public function test_invitation_is_invalid_when_revoked(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        $invitation->markRevoked();
        $invitation->refresh();

        $this->assertFalse($invitation->isValid());
    }

    public function test_find_valid_token_returns_pending_unexpired(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        $found = PortalInvitation::findValidToken($invitation->invitation_token);
        $this->assertNotNull($found);
        $this->assertEquals($invitation->getKey(), $found->getKey());
    }

    public function test_find_valid_token_returns_null_for_expired(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        // Force expiry
        $invitation->update(['expires_at' => now()->subHour()]);

        $found = PortalInvitation::findValidToken($invitation->invitation_token);
        $this->assertNull($found);
    }

    public function test_find_valid_token_returns_null_for_wrong_token(): void
    {
        $found = PortalInvitation::findValidToken('nonexistent-token');
        $this->assertNull($found);
    }

    public function test_present_returns_safe_fields(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
            'test@example.com', '+9779841234567',
        );

        $presented = $invitation->present();

        $this->assertEquals($invitation->getKey(), $presented['id']);
        $this->assertEquals('pending', $presented['status']);
        $this->assertEquals('test@example.com', $presented['email']);
        $this->assertNotNull($presented['expiresAt']);
        $this->assertNull($presented['acceptedAt']);
        $this->assertNull($presented['revokedAt']);

        // Must NOT contain the token
        $this->assertArrayNotHasKey('invitation_token', $presented);
        $this->assertArrayNotHasKey('token', $presented);
    }

    public function test_fillable_fields(): void
    {
        $invitation = new PortalInvitation;
        $fillable = $invitation->getFillable();

        $this->assertContains('tenant_id', $fillable);
        $this->assertContains('facility_id', $fillable);
        $this->assertContains('portal_account_id', $fillable);
        $this->assertContains('patient_id', $fillable);
        $this->assertContains('invitation_token', $fillable);
        $this->assertContains('email', $fillable);
        $this->assertContains('phone', $fillable);
        $this->assertContains('status', $fillable);
        $this->assertContains('expires_at', $fillable);
    }

    public function test_mark_accepted_sets_timestamp(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        $this->assertNull($invitation->accepted_at);
        $invitation->markAccepted();
        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertEquals('accepted', $invitation->status);
    }

    public function test_mark_revoked_sets_timestamp(): void
    {
        $invitation = PortalInvitation::createInvitation(
            'tenant-1', 'facility-1', 'account-1', 'patient-1',
        );

        $this->assertNull($invitation->revoked_at);
        $invitation->markRevoked();
        $invitation->refresh();
        $this->assertNotNull($invitation->revoked_at);
        $this->assertEquals('revoked', $invitation->status);
    }
}
