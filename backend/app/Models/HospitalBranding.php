<?php

namespace App\Models;

use Database\Factories\HospitalBrandingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hospital branding and document configuration per facility.
 * Stores all configurable hospital identity, contact, address, document
 * template, and financial settings needed for forms, PDFs, invoices,
 * receipts, discharge documents, and the application UI.
 */
class HospitalBranding extends Model
{
    /** @use HasFactory<HospitalBrandingFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'hospital_name',
        'hospital_name_local',
        'logo_url',
        'favicon_url',
        'primary_color',
        'secondary_color',
        'phone',
        'emergency_phone',
        'email',
        'website',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'country',
        'postal_code',
        'document_header',
        'document_footer',
        'letterhead_text',
        'date_format',
        'time_format',
        'currency',
        'currency_symbol',
        'vat_rate',
        'vat_number',
        'registration_number',
        'pan_number',
        'terms_and_conditions',
        'privacy_policy',
        'version',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vat_rate' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Format a monetary amount using this facility's currency settings.
     */
    public function formatCurrency(float $amount): string
    {
        $symbol = $this->currency_symbol ?? 'Rs.';
        $decimals = ($this->vat_rate ?? 0) > 0 ? 2 : 0;

        return $symbol.' '.number_format($amount, $decimals);
    }

    /**
     * Format a date using this facility's date format.
     */
    public function formatDate(\DateTimeInterface $date): string
    {
        return $date->format($this->date_format ?? 'Y-m-d');
    }

    /**
     * Format a time using this facility's time format.
     */
    public function formatTime(\DateTimeInterface $date): string
    {
        return $date->format($this->time_format ?? 'H:i');
    }

    /**
     * Present the branding record as an API-safe array.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'tenantId' => $this->tenant_id,
            'facilityId' => $this->facility_id,

            // Identity
            'hospitalName' => $this->hospital_name,
            'hospitalNameLocal' => $this->hospital_name_local,
            'logoUrl' => $this->logo_url,
            'faviconUrl' => $this->favicon_url,
            'primaryColor' => $this->primary_color,
            'secondaryColor' => $this->secondary_color,

            // Contact
            'phone' => $this->phone,
            'emergencyPhone' => $this->emergency_phone,
            'email' => $this->email,
            'website' => $this->website,

            // Address
            'addressLine1' => $this->address_line1,
            'addressLine2' => $this->address_line2,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postalCode' => $this->postal_code,

            // Document
            'documentHeader' => $this->document_header,
            'documentFooter' => $this->document_footer,
            'letterheadText' => $this->letterhead_text,
            'dateFormat' => $this->date_format,
            'timeFormat' => $this->time_format,
            'currency' => $this->currency,
            'currencySymbol' => $this->currency_symbol,
            'vatRate' => $this->vat_rate,
            'vatNumber' => $this->vat_number,
            'registrationNumber' => $this->registration_number,
            'panNumber' => $this->pan_number,

            // Legal
            'termsAndConditions' => $this->terms_and_conditions,
            'privacyPolicy' => $this->privacy_policy,

            'version' => $this->version,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
