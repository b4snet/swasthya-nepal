<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One registry entry in the integration inventory (DATABASE.md §3.42,
 * INTEROPERABILITY.md §13–14): what is connected, its MEASURED status
 * (configured/active/degraded/disabled — recorded by status checks, never
 * asserted), contract/standards/mapping versions, kill-switch, and health.
 * A readiness-layer entry (e.g. `fhir`/`hl7`/`dicom` with provider
 * `swasthya`) records that OUR projection layer is active — it never claims
 * a live national/LIS/PACS connection. Tenant-scoped, RLS on + FORCED.
 */
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use HasFactory, HasUuid;

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_SMS = 'sms';

    public const TYPE_EMAIL = 'email';

    public const TYPE_LAB = 'lab';

    public const TYPE_PACS = 'pacs';

    public const TYPE_FHIR = 'fhir';

    public const TYPE_HL7 = 'hl7';

    public const TYPE_DICOM = 'dicom';

    public const TYPE_NATIONAL = 'national';

    public const STATUS_CONFIGURED = 'configured';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'type',
        'provider',
        'config_encrypted',
        'status',
        'owner_staff_id',
        'purpose',
        'contract_version',
        'standards_version',
        'mapping_version',
        'kill_switched',
        'last_checked_at',
        'health',
        'lock_version',
        'created_by_staff_id',
        'updated_by_staff_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'config_encrypted' => 'array',
            'health' => 'array',
            'kill_switched' => 'boolean',
            'last_checked_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /**
     * @return HasMany<IntegrationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class, 'integration_id');
    }
}
