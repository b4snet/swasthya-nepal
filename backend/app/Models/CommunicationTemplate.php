<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Hospital communication template (Phase 81): configurable multi-channel
 * templates for appointment confirmations, reminders, follow-ups, results,
 * billing, discharge, and portal invitations.
 *
 * Each template supports in-app, email, SMS, and WhatsApp channels with
 * per-channel content variants. Variables use {{name}} syntax.
 */
class CommunicationTemplate extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    // Categories
    public const CATEGORY_APPOINTMENT = 'appointment';

    public const CATEGORY_FOLLOWUP = 'followup';

    public const CATEGORY_RESULT = 'result';

    public const CATEGORY_BILLING = 'billing';

    public const CATEGORY_DISCHARGE = 'discharge';

    public const CATEGORY_PORTAL = 'portal';

    public const CATEGORY_GENERAL = 'general';

    // Types
    public const TYPE_CONFIRMATION = 'confirmation';

    public const TYPE_REMINDER = 'reminder';

    public const TYPE_MISSED = 'missed';

    public const TYPE_INVITATION = 'invitation';

    public const TYPE_NOTIFICATION = 'notification';

    public const TYPE_ALERT = 'alert';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'category',
        'type',
        'channel_in_app',
        'channel_email',
        'channel_sms',
        'channel_whatsapp',
        'subject',
        'body_template',
        'whatsapp_message',
        'sms_message',
        'variables',
        'retry_count',
        'retry_delay_minutes',
        'enabled',
        'locale',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel_in_app' => 'boolean',
            'channel_email' => 'boolean',
            'channel_sms' => 'boolean',
            'channel_whatsapp' => 'boolean',
            'variables' => 'array',
            'metadata' => 'array',
            'retry_count' => 'integer',
            'retry_delay_minutes' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }

    /**
     * Render the body template with the given variables.
     * Returns the channel-appropriate content.
     *
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, body: string, sms: string|null, whatsapp: string|null}
     */
    public function render(array $variables = []): array
    {
        $render = function (string $text) use ($variables): string {
            foreach ($variables as $key => $value) {
                $text = str_replace('{{'.$key.'}}', (string) $value, $text);
            }

            return $text;
        };

        return [
            'subject' => $render($this->subject ?? ''),
            'body' => $render($this->body_template),
            'sms' => $this->sms_message ? $render($this->sms_message) : null,
            'whatsapp' => $this->whatsapp_message ? $render($this->whatsapp_message) : null,
        ];
    }

    /**
     * Get available variables for this template.
     *
     * @return list<array{name: string, label: string, type: string, required: bool, example: string}>
     */
    public function availableVariables(): array
    {
        return $this->variables ?? [];
    }

    /**
     * List all categories.
     *
     * @return array<string, string>
     */
    public static function categories(): array
    {
        return [
            self::CATEGORY_APPOINTMENT => 'Appointment',
            self::CATEGORY_FOLLOWUP => 'Follow-up',
            self::CATEGORY_RESULT => 'Result',
            self::CATEGORY_BILLING => 'Billing',
            self::CATEGORY_DISCHARGE => 'Discharge',
            self::CATEGORY_PORTAL => 'Portal',
            self::CATEGORY_GENERAL => 'General',
        ];
    }

    /**
     * List all types.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_CONFIRMATION => 'Confirmation',
            self::TYPE_REMINDER => 'Reminder',
            self::TYPE_MISSED => 'Missed',
            self::TYPE_INVITATION => 'Invitation',
            self::TYPE_NOTIFICATION => 'Notification',
            self::TYPE_ALERT => 'Alert',
        ];
    }

    /**
     * Present the template as an API-safe array.
     *
     * @return array<string, mixed>
     */
    public function present(): array
    {
        return [
            'id' => $this->getKey(),
            'tenantId' => $this->tenant_id,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'type' => $this->type,
            'channels' => [
                'inApp' => $this->channel_in_app,
                'email' => $this->channel_email,
                'sms' => $this->channel_sms,
                'whatsapp' => $this->channel_whatsapp,
            ],
            'subject' => $this->subject,
            'bodyTemplate' => $this->body_template,
            'whatsappMessage' => $this->whatsapp_message,
            'smsMessage' => $this->sms_message,
            'variables' => $this->variables,
            'retryCount' => $this->retry_count,
            'retryDelayMinutes' => $this->retry_delay_minutes,
            'enabled' => $this->enabled,
            'locale' => $this->locale,
            'metadata' => $this->metadata,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
