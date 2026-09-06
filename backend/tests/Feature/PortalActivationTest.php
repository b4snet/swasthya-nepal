<?php

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\Organization;
use App\Models\Patient;
use App\Models\PortalAccount;
use App\Models\PortalInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Portal activation tests (Phase 82): invitation lifecycle, token
 * validation, and cross-patient isolation.
 */
class PortalActivationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private Facility $facility;
    private PortalAccount $portalAccount;
    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->facility = Facility::factory()->create([
            'tenant_id' => $this->org->getKey(),
        ]);
        $this->patient = Patient::factory()->create([
            'tenant_id' => $this->org->getKey(),
            'facility_id' => $this->facility->getKey(),
        ]);
        $this->portalAccount = PortalAccount::factory()->create([
            'tenant_id' => $this->org->getKey(),
            'facility_id' => $this->facility->getKey(),
            'patient_id' => $this->patient->getKey(),
        ]);
    }

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
            $this->org->getKey(),
            $this->facility->getKey(),
            $this->portalAccount->getKey(),
            $this->patient->getKey(),
            'test@example.com',
            '+9779841234567',
        );

        $this->assertNotNull($invitation->getKey());
        $this->assertEquals('pending', $invitation->status);
        $this->assertEquals($this->org->getKey(), $invitation->tenant_id);
        $this->assertEquals($this->patient->getKey(), $invitation->patient_id);
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
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
        );

        $this->assertTrue($invitation->isValid());
    }

    public function test_invitation_is_invalid_when_accepted(): void
    {
        $invitation = PortalInvitation::createInvitation(
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
        );

        $invitation->markAccepted();
        $invitation->refresh();

        $this->assertFalse($invitation->isValid());
    }

    public function test_invitation_is_invalid_when_revoked(): void
    {
        $invitation = PortalInvitation::createInvitation(
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
        );

        $invitation->markRevoked();
        $invitation->refresh();

        $this->assertFalse($invitation->isValid());
    }

    public function test_find_valid_token_returns_pending_unexpired(): void
    {
        $invitation = PortalInvitation::createInvitation(
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
        );

        $found = PortalInvitation::findValidToken($invitation->invitation_token);
        $this->assertNotNull($found);
        $this->assertEquals($invitation->getKey(), $found->getKey());
    }

    public function test_find_valid_token_returns_null_for_expired(): void
    {
        $invitation = PortalInvitation::createInvitation(
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
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
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
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
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
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
            $this->org->getKey(), $this->facility->getKey(),
            $this->portalAccount->getKey(), $this->patient->getKey(),
        );

        $this->assertNull($invitation->revoked_at);
        $invitation->markRevoked();
        $invitation->refresh();
        $this->assertNotNull($invitation->revoked_at);
        $this->assertEquals('revoked', $invitation->status);
    }
}
