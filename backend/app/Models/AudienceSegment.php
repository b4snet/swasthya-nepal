<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable audience segment (Phase 12).
 *
 * Defines targeting criteria for broadcast campaigns.
 * Supports: national, organization, facility, department, role, custom scope.
 */
class AudienceSegment extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'scope_type',
        'criteria',
        'estimated_recipients',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'criteria' => 'array',
            'estimated_recipients' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    public function campaigns()
    {
        return $this->hasMany(BroadcastCampaign::class, 'segment_id');
    }

    /**
     * Resolve the user IDs that match this segment's criteria.
     *
     * @return list<string> User IDs
     */
    public function resolveRecipientIds(): array
    {
        $query = User::query();

        // Scope filtering
        $criteria = $this->criteria;

        if (isset($criteria['role_codes']) && is_array($criteria['role_codes'])) {
            $roleIds = Role::whereIn('code', $criteria['role_codes'])->pluck('id');
            $assignmentUserIds = RoleAssignment::whereIn('role_id', $roleIds)
                ->where('status', 'active')
                ->pluck('user_id');
            $query->whereIn('id', $assignmentUserIds);
        }

        if (isset($criteria['facility_ids']) && is_array($criteria['facility_ids'])) {
            $assignmentUserIds = RoleAssignment::whereIn('facility_id', $criteria['facility_ids'])
                ->where('status', 'active')
                ->pluck('user_id');
            $query->whereIn('id', $assignmentUserIds);
        }

        if (isset($criteria['department_ids']) && is_array($criteria['department_ids'])) {
            $staffIds = Staff::whereIn('department_id', $criteria['department_ids'])
                ->pluck('user_id');
            $query->whereIn('id', $staffIds);
        }

        return $query->distinct()->pluck('id')->toArray();
    }
}
