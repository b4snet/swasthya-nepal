<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportTemplateVersion extends Model
{
    use HasFactory, HasUuid;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'facility_id', 'template_id', 'version', 'snapshot',
        'change_reason', 'created_by_staff_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }
}
