<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MultidisciplinaryReview extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'tenant_id', 'facility_id', 'oncology_profile_id',
        'review_date', 'decision', 'recommendations', 'attendees',
        'reviewed_by_staff_id',
    ];

    protected function casts(): array
    {
        return ['attendees' => 'array', 'review_date' => 'datetime'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OncologyProfile::class, 'oncology_profile_id');
    }
}
