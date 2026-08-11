<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Availability derivation (DATABASE.md §3.16: "availability *slots* are
 * derived, never stored"). For a given provider and date, the open slots
 * are: template occurrences for that weekday, minus exceptions, minus
 * already-live bookings — capacity is honored per slot.
 *
 * This is the booking truth: the appointment controller validates the
 * requested slot against the same derivation and races on the partial unique
 * index, so a slot can never be double-booked even under parallel requests.
 */
final class SlotService
{
    /**
     * Live statuses that hold a slot.
     */
    private const HOLDING_STATUSES = ['booked', 'checked_in', 'in_consultation'];

    /**
     * @return Collection<int, array{startsAt: string, endsAt: string, templateId: string, capacity: int, booked: int, available: bool}>
     */
    public function slotsFor(string $tenantId, string $providerStaffId, string $date, bool $includeUnavailable = false): Collection
    {
        // 0 (Sun) .. 6 (Sat) — matches the schedule_templates check.
        $day = (int) CarbonImmutable::parse($date)->format('w');

        $templates = ScheduleTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $providerStaffId)
            ->where('day_of_week', $day)
            ->where('status', ScheduleTemplate::STATUS_ACTIVE)
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            })
            ->get();

        $hasException = ScheduleException::query()
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $providerStaffId)
            ->where('exception_date', $date)
            ->where('status', ScheduleException::STATUS_ACTIVE)
            ->exists();

        if ($hasException) {
            return collect();
        }

        $holding = Appointment::query()
            ->where('tenant_id', $tenantId)
            ->where('provider_staff_id', $providerStaffId)
            ->whereIn('status', self::HOLDING_STATUSES)
            ->whereDate('starts_at', $date)
            ->selectRaw('starts_at, count(*) as taken')
            ->groupBy('starts_at')
            ->pluck('taken', 'starts_at')
            ->mapWithKeys(fn ($taken, $key): array => [(string) CarbonImmutable::parse($key)->toISOString() => (int) $taken]);

        $slots = collect();

        foreach ($templates as $template) {
            $start = CarbonImmutable::parse($date.' '.$template->starts_at->format('H:i:s'));
            $end = CarbonImmutable::parse($date.' '.$template->ends_at->format('H:i:s'));

            for (; $start->lt($end); $start = $start->addMinutes($template->slot_minutes)) {
                $slotEnd = $start->addMinutes($template->slot_minutes);
                $booked = (int) ($holding->get($start->toISOString()) ?? 0);

                if ($includeUnavailable || $booked < $template->capacity) {
                    $slots->push([
                        'startsAt' => $start->toISOString(),
                        'endsAt' => $slotEnd->toISOString(),
                        'templateId' => $template->getKey(),
                        'capacity' => (int) $template->capacity,
                        'booked' => $booked,
                        'available' => $booked < $template->capacity,
                    ]);
                }
            }
        }

        return $slots;
    }
}
