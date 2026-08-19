<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reusable notification template (Phase 12).
 *
 * Templates define the message structure per channel with {{variable}}
 * placeholders. Tenant-scoped.
 */
class NotificationTemplate extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'channel',
        'type',
        'subject',
        'body_template',
        'locale',
        'active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'active' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    /**
     * Render the template with the given variables.
     */
    public function render(array $variables = []): array
    {
        $body = $this->body_template;
        $subject = $this->subject;

        foreach ($variables as $key => $value) {
            $body = str_replace("{{{$key}}}", (string) $value, $body);
            $subject = str_replace("{{{$key}}}", (string) $value, $subject);
        }

        return [
            'subject' => $subject,
            'body' => $body,
        ];
    }
}
