<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\ChecklistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One safety-checklist step for a surgical procedure, with recorded
 * completion (WHO completed it, WHEN — DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10). A procedure cannot close with incomplete
 * checklist items (checklist compliance is a safety metric). Tenant+facility
 * scoped, RLS on + FORCED.
 */
class ChecklistItem extends Model
{
    /** @use HasFactory<ChecklistItemFactory> */
    use HasFactory, HasUuid;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'checklist_template_id',
        'step_key',
        'step_label',
        'sequence',
        'category',
        'completed_at',
        'completed_by_staff_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'completed_at' => 'datetime',
        ];
    }
}
