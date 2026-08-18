<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\HasUuid;
use Database\Factories\VendorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The vendor master (PRODUCT_REQUIREMENTS §6.16, DATABASE.md §3.32).
 * Tax id and bank details are encrypted at rest (EncryptedString cast) and
 * never logged; status is active | blacklisted.
 */
class Vendor extends Model
{
    /** @use HasFactory<VendorFactory> */
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLACKLISTED = 'blacklisted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'code',
        'name',
        'tax_id_encrypted',
        'bank_details_encrypted',
        'status',
        'rating',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_id_encrypted' => EncryptedString::class,
            'bank_details_encrypted' => EncryptedString::class,
            'rating' => 'array',
        ];
    }

    /**
     * @return HasMany<VendorContract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(VendorContract::class, 'vendor_id');
    }
}
