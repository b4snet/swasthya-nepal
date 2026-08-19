<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\PortalAccessGrantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consent-bound portal access grant (DATABASE.md §3.53, PRODUCT
 * REQUIREMENTS §6.2): staff grant a data scope (appointments / results /
 * bills) to a patient for a stated purpose; the PATIENT can revoke it
 * themselves. One ACTIVE grant per (patient, scope) — the DB partial unique
 * backstops concurrent double-grants; revocation is CAS on
 * (status='granted', lock_version). Purpose limitation: every grant carries
 * its purpose; the grantee sees only the granted scopes.
 * Tenant+facility scoped, RLS on + FORCED.
 */
class PortalAccessGrant extends Model
{
    /** @use HasFactory<PortalAccessGrantFactory> */
    use HasFactory, HasUuid;

    public const SCOPE_APPOINTMENTS = 'appointments';

    public const SCOPE_RESULTS = 'results';

    public const SCOPE_BILLS = 'bills';

    public const SCOPE_MEDICAL_HISTORY = 'medical_history';

    public const SCOPE_PRESCRIPTIONS = 'prescriptions';

    public const SCOPE_DOCUMENTS = 'documents';

    public const SCOPE_RADIOLOGY = 'radiology';

    public const SCOPE_REFERRALS = 'referrals';

    public const SCOPE_CARE_PLANS = 'care_plans';

    public const SCOPE_IMMUNIZATIONS = 'immunizations';

    public const SCOPE_MESSAGING = 'messaging';

    public const STATUS_GRANTED = 'granted';

    public const STATUS_REVOKED = 'revoked';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'portal_account_id',
        'patient_id',
        'data_scope',
        'purpose',
        'status',
        'granted_at',
        'granted_by_staff_id',
        'revoked_at',
        'revoked_by_staff_id',
        'revoked_by_patient',
        'lock_version',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'revoked_by_patient' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PortalAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(PortalAccount::class, 'portal_account_id');
    }
}
