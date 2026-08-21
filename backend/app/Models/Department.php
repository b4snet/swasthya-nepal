<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Organizational structure within a facility (DATABASE.md §3.8) — OPD,
 * surgery, pharmacy, HR… Used by staff, inventory, and reporting.
 *
 * Tenant-scoped (tenant_id NOT NULL). The self-referencing hierarchy is
 * tenant-safe: a department's parent is enforced to live in the same
 * tenant and facility by a composite FK (DATABASE.md §0.9).
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * Department types — configurable hospital structure.
     */
    public const TYPES = [
        'medical', 'supportive', 'surgical', 'administrative',
        'emergency', 'diagnostic', 'pharmacy', 'laboratory',
        'radiology', 'other',
    ];

    /**
     * Pre-defined medical departments (configurable by admin).
     */
    public const MEDICAL_DEPARTMENTS = [
        'gynecology_obstetrics' => 'Gynaecology and Obstetrics',
        'psychiatry' => 'Psychiatry',
        'neurology' => 'Neurology',
        'cardiology' => 'Cardiology',
        'pediatrics' => 'Paediatrics',
        'rheumatology' => 'Rheumatology',
        'internal_medicine' => 'Internal Medicine',
        'dermatology' => 'Dermatology',
        'nephrology' => 'Nephrology',
        'gastroenterology' => 'Gastroenterology',
        'acupuncture' => 'Acupuncture',
        'endocrinology' => 'Endocrinology',
        'ophthalmology' => 'Ophthalmology',
        'ent' => 'ENT',
        'urology' => 'Urology',
        'oncology' => 'Oncology',
        'pulmonology' => 'Pulmonology',
        'hematology' => 'Hematology',
    ];

    /**
     * Pre-defined surgical departments.
     */
    public const SURGICAL_DEPARTMENTS = [
        'general_surgery' => 'General Surgery',
        'cardiovascular_surgery' => 'Cardiovascular Surgery',
        'pediatric_surgery' => 'Paediatric Surgery',
        'spine_surgery' => 'Spine Surgery',
        'neurosurgery' => 'Neurosurgery',
        'plastic_surgery' => 'Plastic Surgery',
        'orthopedic_surgery' => 'Orthopedic Surgery',
        'gi_laparoscopic' => 'GI/Laparoscopic Surgery',
        'dental' => 'Dental',
    ];

    /**
     * Pre-defined supportive service departments.
     */
    public const SUPPORTIVE_DEPARTMENTS = [
        'radiology_imaging' => 'Radiology & Imaging',
        'physiotherapy' => 'Physiotherapy',
        'vaccination' => 'Vaccination',
        'laboratory' => 'Laboratory',
        'dietician' => 'Dietician/Nutrition',
        'pharmacy' => 'Pharmacy',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id', 'facility_id', 'branch_id', 'name', 'code', 'status',
        'parent_department_id', 'department_type', 'description', 'phone', 'location',
        'operating_hours', 'appointment_availability', 'queue_settings',
        'responsible_roles', 'sort_order', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'operating_hours' => 'array',
        'appointment_availability' => 'array',
        'queue_settings' => 'array',
        'responsible_roles' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<Facility, $this>
     */
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_department_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_department_id');
    }

    /**
     * @return HasMany<Staff, $this>
     */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class, 'department_id');
    }
}
