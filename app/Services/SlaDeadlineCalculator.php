<?php

namespace App\Services;

use App\Models\SellerProfile;
use App\Models\StoreOperatingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SlaDeadlineCalculator
{
    /**
     * Calculate SLA deadline for a manual trade order, shifting start time to the next valid
     * store opening window when the order is paid outside operating hours.
     *
     * @param  Collection<int, StoreOperatingHour>  $operatingHours
     */
    public function calculateDeadline(
        CarbonImmutable $paidAt,
        int $slaMinutes,
        ?SellerProfile $sellerProfile,
        Collection $operatingHours,
    ): CarbonImmutable {
        $effectiveSlaMinutes = max(1, $slaMinutes);

        if (
            $sellerProfile === null
            || (! $sellerProfile->operating_hours_enabled && ! $sellerProfile->auto_schedule_enabled)
            || $operatingHours->isEmpty()
        ) {
            return $paidAt->addMinutes($effectiveSlaMinutes);
        }

        $storeTimezone = ! empty($sellerProfile->operating_timezone)
            ? (string) $sellerProfile->operating_timezone
            : 'Asia/Jakarta';
        $appTimezone = $paidAt->getTimezone();
        $localPaidAt = $paidAt->setTimezone($storeTimezone);

        /** @var Collection<int, StoreOperatingHour> $hoursByDay */
        $hoursByDay = $operatingHours->keyBy(static fn (StoreOperatingHour $h): int => (int) $h->day_of_week);

        // Search up to 8 days ahead (today + 7 days) to find the current or next valid opening window
        for ($dayOffset = 0; $dayOffset <= 7; $dayOffset++) {
            $candidateDate = $localPaidAt->addDays($dayOffset);
            $dow = (int) $candidateDate->dayOfWeek;

            /** @var StoreOperatingHour|null $daySchedule */
            $daySchedule = $hoursByDay->get($dow);
            if ($daySchedule === null || $daySchedule->is_closed) {
                continue;
            }

            [$openH, $openM, $openS] = $this->parseTimeParts((string) $daySchedule->open_time, [8, 0, 0]);
            [$closeH, $closeM, $closeS] = $this->parseTimeParts((string) $daySchedule->close_time, [22, 0, 0]);

            $openAt = $candidateDate->setTime($openH, $openM, $openS);
            $closeAt = $candidateDate->setTime($closeH, $closeM, $closeS);

            if ($closeAt->lessThan($openAt)) {
                $closeAt = $closeAt->addDay();
            }

            if ($dayOffset === 0) {
                // Order paid before today's opening hour -> SLA starts at today's opening hour
                if ($localPaidAt->lessThan($openAt)) {
                    return $openAt->addMinutes($effectiveSlaMinutes)->setTimezone($appTimezone);
                }

                // Order paid inside today's operating window -> SLA starts immediately at paidAt
                if ($localPaidAt->lessThanOrEqualTo($closeAt)) {
                    return $localPaidAt->addMinutes($effectiveSlaMinutes)->setTimezone($appTimezone);
                }

                // Order paid after today's closing hour -> check subsequent days
                continue;
            }

            return $openAt->addMinutes($effectiveSlaMinutes)->setTimezone($appTimezone);
        }

        // Safe fallback if all 7 days in the schedule are marked closed
        return $paidAt->addMinutes($effectiveSlaMinutes);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $default
     * @return array{0: int, 1: int, 2: int}
     */
    private function parseTimeParts(string $timeStr, array $default): array
    {
        $clean = trim($timeStr);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $clean, $matches)) {
            return [
                min(23, max(0, (int) $matches[1])),
                min(59, max(0, (int) $matches[2])),
                isset($matches[3]) ? min(59, max(0, (int) $matches[3])) : 0,
            ];
        }

        return $default;
    }
}
