<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSubscription extends Model
{
    use HasFactory, HasUuid;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const FREQUENCY_DAILY = 'daily';

    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_ON_PUBLISH = 'on_publish';

    protected $fillable = [
        'tenant_id', 'facility_id', 'staff_id',
        'report_template_id', 'compliance_report_code',
        'frequency', 'delivery_method', 'preferences',
        'status', 'last_delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'preferences' => 'array',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'staff_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'report_template_id');
    }
}
