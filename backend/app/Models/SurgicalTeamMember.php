<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Database\Factories\SurgicalTeamMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A logged team member on a surgical procedure (DATABASE.md §3.48,
 * PRODUCT_REQUIREMENTS §6.10). Append-only log. Tenant+facility scoped,
 * RLS on + FORCED.
 */
class SurgicalTeamMember extends Model
{
    /** @use HasFactory<SurgicalTeamMemberFactory> */
    use HasFactory, HasUuid;

    public const ROLE_SURGEON = 'surgeon';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_ANESTHETIST = 'anesthetist';

    public const ROLE_NURSE = 'nurse';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'facility_id',
        'procedure_id',
        'staff_id',
        'role',
        'time_in',
        'time_out',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'time_in' => 'datetime',
            'time_out' => 'datetime',
        ];
    }
}
