<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\HasUuid;
use Database\Factories\PatientIdentifierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * A patient identity document (DATABASE.md §3.12): national ID, passport,
 * driving license, other.
 *
 * The `value` mutator is the ONLY writer: it encrypts the plaintext into
 * `value_encrypted` (ciphertext at rest, SECURITY.md §12) and stores a
 * deterministic sha256 in `value_hash` for duplicate DETECTION — a hash
 * match surfaces merge candidates, never an auto-merge. The stored value
 * reads back through the EncryptedString cast; `value_hash` is a hash and
 * is not reversible.
 *
 * Superseded by status, never deleted (identifiers underpin deduplication
 * history).
 */
class PatientIdentifier extends Model
{
    /** @use HasFactory<PatientIdentifierFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'patient_id',
        'type',
        'value_encrypted',
        'value_hash',
        'issuing_country',
        'is_verified',
        'verified_by',
        'verified_at',
        'status',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_encrypted' => EncryptedString::class,
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * The plaintext identity value. Setting it encrypts the column and
     * derives the dedupe hash in one step.
     */
    public function setValueAttribute(?string $value): void
    {
        $value = is_string($value) ? trim($value) : null;

        $this->attributes['value_encrypted'] = $value === null || $value === ''
            ? null
            : Crypt::encryptString($value);

        $this->attributes['value_hash'] = $value === null || $value === ''
            ? null
            : self::hashValue($value);
    }

    /**
     * Deterministic, whitespace-insensitive hash for duplicate detection.
     */
    public static function hashValue(string $value): string
    {
        return hash('sha256', strtolower(preg_replace('/\s+/', '', $value) ?? ''));
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id');
    }
}
