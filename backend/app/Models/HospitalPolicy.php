<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HospitalPolicy extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_REVIEW = 'review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'tenant_id', 'facility_id', 'policy_code', 'title', 'category',
        'owner_staff_id', 'version', 'content', 'effective_date',
        'review_date', 'status', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'effective_date' => 'date',
            'review_date' => 'date',
            'version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class, 'facility_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'id');
    }
}
