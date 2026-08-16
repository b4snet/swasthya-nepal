<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\OauthPartnerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A registered OAuth2 partner (INTEROPERABILITY.md §11, SECURITY.md §5):
 * tenant-scoped client_credentials machine access. `client_secret_hash` and
 * `webhook_secret_hash` are hashes at rest — the client secret is shown once
 * at registration, the webhook secret verifies inbound HMAC signatures.
 * `scopes` bounds what the partner may request; tokens are short-lived,
 * scoped, hash-at-rest, and revocable. Tenant-scoped, RLS on + FORCED.
 */
class OauthPartner extends Model
{
    /** @use HasFactory<OauthPartnerFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const SCOPE_FHIR_PATIENT = 'fhir:Patient';

    public const SCOPE_FHIR_ENCOUNTER = 'fhir:Encounter';

    public const SCOPE_FHIR_MEDICATION_REQUEST = 'fhir:MedicationRequest';

    public const SCOPE_FHIR_DIAGNOSTIC_REPORT = 'fhir:DiagnosticReport';

    public const ALL_SCOPES = [
        self::SCOPE_FHIR_PATIENT,
        self::SCOPE_FHIR_ENCOUNTER,
        self::SCOPE_FHIR_MEDICATION_REQUEST,
        self::SCOPE_FHIR_DIAGNOSTIC_REPORT,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'client_id',
        'client_secret_hash',
        'scopes',
        'status',
        'token_ttl_seconds',
        'webhook_url',
        'webhook_secret_hash',
        'created_by_staff_id',
        'lock_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'token_ttl_seconds' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<OauthPartnerToken, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(OauthPartnerToken::class, 'oauth_partner_id');
    }
}
