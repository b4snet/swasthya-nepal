<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormTemplate extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'facility_id', 'code', 'name', 'slug', 'description',
        'category', 'subcategory', 'module', 'department', 'specialty', 'workflow',
        'schema', 'layout', 'allowed_roles', 'required_modules',
        'version', 'is_active', 'is_published',
        'printable', 'pdf_capable', 'print_config',
        'linked_to_patient', 'linked_to_encounter', 'linked_to_admission', 'linked_to_appointment',
        'generates_document_number', 'document_number_prefix',
        'metadata',
    ];

    protected $casts = [
        'schema' => 'array',
        'layout' => 'array',
        'allowed_roles' => 'array',
        'required_modules' => 'array',
        'print_config' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'is_published' => 'boolean',
        'printable' => 'boolean',
        'pdf_capable' => 'boolean',
        'linked_to_patient' => 'boolean',
        'linked_to_encounter' => 'boolean',
        'linked_to_admission' => 'boolean',
        'linked_to_appointment' => 'boolean',
        'generates_document_number' => 'boolean',
        'version' => 'integer',
    ];

    // ── Categories ──
    public const CATEGORIES = [
        'registration', 'clinical', 'consent', 'specialty', 'pediatric',
        'mental_health', 'nutrition', 'rehabilitation', 'dental', 'eye',
        'imaging', 'laboratory', 'admission', 'icu', 'pharmacy',
        'referral', 'insurance', 'telemedicine', 'wellness', 'diagnostic',
        'nursing', 'operating_theatre', 'blood_bank', 'emergency',
    ];

    // ── Modules ──
    public const MODULES = [
        'patient', 'appointment', 'encounter', 'emr', 'pharmacy',
        'laboratory', 'radiology', 'ipd', 'billing', 'emergency',
        'nursing', 'blood_bank', 'procurement', 'oncology', 'telehealth',
        'portal', 'admin', 'hr', 'finance',
    ];

    // ── Workflows ──
    public const WORKFLOWS = [
        'intake', 'assessment', 'consultation', 'procedure', 'discharge',
        'follow_up', 'screening', 'consent', 'order', 'result',
        'verification', 'report', 'documentation',
    ];

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class, 'template_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeForModule($query, string $module)
    {
        return $query->where('module', $module);
    }

    public function scopeForRole($query, string $roleCode)
    {
        return $query->whereJsonContains('allowed_roles', $roleCode);
    }

    /**
     * Check if a given role can use this template.
     */
    public function isAccessibleByRole(string $roleCode): bool
    {
        if (empty($this->allowed_roles)) {
            return true; // No role restriction
        }

        return in_array($roleCode, $this->allowed_roles);
    }

    /**
     * Get the latest active version of this template.
     */
    public function scopeLatestVersion($query)
    {
        return $query->where('is_active', true)->orderByDesc('version');
    }
}
