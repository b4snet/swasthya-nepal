<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\ScheduleException;
use App\Models\ScheduleTemplate;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Availability derivation (DATABASE.md §3.16: "availability *slots* are
 * derived, never stored"). For a given provider and date, the open slots
 * are: template occurrences for that weekday, minus exceptions, minus
 * already-live bookings — capacity is honored per slot.
 *
 * When a serviceId is provided, the derivation is service-duration-aware:
 * slots are grouped into contiguous blocks matching the service's
 * `default_duration_minutes`, and a slot is only available if ALL
 * consecutive sub-slots within the block are available.
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
    public function slotsFor(string $tenantId, string $providerStaffId, string $date, bool $includeUnavailable = false, ?string $serviceId = null): Collection
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

        // Resolve service duration if a service is specified
        $serviceDuration = null;
        if ($serviceId !== null) {
            $service = Service::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $serviceId)
                ->where('status', Service::STATUS_ACTIVE)
                ->first();

            if ($service !== null && $service->default_duration_minutes !== null) {
                $serviceDuration = (int) $service->default_duration_minutes;
            }
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
            $slotMins = (int) $template->slot_minutes;

            // When a service duration is specified, we generate "block slots"
            // — each block spans multiple template sub-slots. A block is
            // available only if ALL sub-slots within it are available.
            if ($serviceDuration !== null && $serviceDuration > $slotMins) {
                $blockSlots = $this->generateBlockSlots(
                    $start, $end, $slotMins, $serviceDuration,
                    (int) $template->capacity, $holding,
                    $template->getKey(), $includeUnavailable,
                );
                $slots = $slots->concat($blockSlots);
            } else {
                // Standard single-slot generation
                for (; $start->lt($end); $start = $start->addMinutes($slotMins)) {
                    $slotEnd = $start->addMinutes($slotMins);
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
        }

        return $slots;
    }

    /**
     * Generate block slots when a service duration exceeds the template's
     * slot_minutes. Each block is a contiguous range of sub-slots; the block
     * is available only if ALL sub-slots have capacity remaining.
     *
     * @param  Collection<string, int>  $holding  booking counts keyed by ISO start time
     * @return Collection<int, array{startsAt: string, endsAt: string, templateId: string, capacity: int, booked: int, available: bool}>
     */
    private function generateBlockSlots(
        CarbonImmutable $templateStart,
        CarbonImmutable $templateEnd,
        int $slotMinutes,
        int $serviceDuration,
        int $capacity,
        Collection $holding,
        string $templateId,
        bool $includeUnavailable,
    ): Collection {
        $slots = collect();
        $subSlotsNeeded = (int) ceil($serviceDuration / $slotMinutes);

        // Walk through template time in sub-slot increments
        $blockStart = $templateStart;
        while (true) {
            $blockEnd = $blockStart->addMinutes($serviceDuration);

            // Block must fit within the template window
            if ($blockEnd->gt($templateEnd)) {
                break;
            }

            // Check all sub-slots within this block
            $allAvailable = true;
            $maxBooked = 0;
            for ($i = 0; $i < $subSlotsNeeded; $i++) {
                $subStart = $blockStart->addMinutes($i * $slotMinutes);
                $booked = (int) ($holding->get($subStart->toISOString()) ?? 0);
                $maxBooked = max($maxBooked, $booked);
                if ($booked >= $capacity) {
                    $allAvailable = false;
                    break;
                }
            }

            if ($includeUnavailable || $allAvailable) {
                $slots->push([
                    'startsAt' => $blockStart->toISOString(),
                    'endsAt' => $blockEnd->toISOString(),
                    'templateId' => $templateId,
                    'capacity' => $capacity,
                    'booked' => $maxBooked,
                    'available' => $allAvailable,
                ]);
            }

            $blockStart = $blockStart->addMinutes($slotMinutes);
        }

        return $slots;
    }
}
