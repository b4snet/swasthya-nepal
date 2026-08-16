<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\CriticalCareNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Critical-care documentation (DATABASE.md §3.49, PRODUCT_REQUIREMENTS
 * §6.11): daily goals, sedation scales, weaning plans, procedures.
 * Content is clinical PHI — stored, never in audit payloads. Tenant+facility
 * scoped, RLS on + FORCED.
 */
class CriticalCareNote extends Model
{
    /** @use HasFactory<CriticalCareNoteFactory> */
    use HasFactory, HasUuid;

    public const TYPE_DAILY_GOAL = 'daily_goal';

    public const TYPE_SEDATION_SCALE = 'sedation_scale';

    public const TYPE_WEANING_PLAN = 'weaning_plan';

    public const TYPE_PROCEDURE = 'procedure';

    public const TYPE_OTHER = 'other';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'icu_admission_id',
        'note_type',
        'content',
        'authored_at',
        'authored_by_staff_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authored_at' => 'datetime',
        ];
    }
}
