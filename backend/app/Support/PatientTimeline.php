<?php

namespace App\Support;

use App\Models\Patient;
use App\Models\PatientTimelineEntry;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The ONLY writer of patient_timeline_entries (PRODUCT_REQUIREMENTS §6.1).
 *
 * Every patient action records a timeline entry alongside its audit event:
 * registration, identifier/contact/policy changes, consents, documents,
 * merges. Entries carry facts and references — never clinical content
 * (MASTER_RULES.md §10.5 no-PHI rule).
 */
final class PatientTimeline
{
    /**
     * @param  array<string, mixed>  $summary
     */
    public function record(
        Patient $patient,
        string $eventType,
        array $summary = [],
        ?Request $request = null,
        ?User $actor = null,
    ): PatientTimelineEntry {
        $context = TenantContext::current();
        $actor ??= $context->user;

        return PatientTimelineEntry::query()->create([
            'tenant_id' => $patient->tenant_id,
            'patient_id' => $patient->getKey(),
            'occurred_at' => now(),
            'event_type' => $eventType,
            'summary' => $summary,
            'actor_id' => $actor?->getKey(),
            'correlation_id' => $request?->attributes->get('correlation_id'),
        ]);
    }
}
