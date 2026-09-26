<?php

namespace App\Domain\Notify;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The do-not-disturb window (config infolink.notifications.quiet_hours_start/end, app timezone, may wrap midnight).
 */
class QuietHours
{
    public function contains(CarbonInterface $moment): bool
    {
        $time = $moment->format('H:i');
        $start = $this->start();
        $end = $this->end();

        if ($start === $end) {
            return false;
        }

        return $start < $end
            ? $time >= $start && $time < $end
            : $time >= $start || $time < $end;
    }

    /**
     * The first moment after the given one at which quiet hours end.
     */
    public function endAfter(CarbonInterface $moment): CarbonImmutable
    {
        $moment = CarbonImmutable::instance($moment);
        [$hour, $minute] = array_map('intval', explode(':', $this->end()));
        $end = $moment->setTime($hour, $minute);

        return $end->lessThanOrEqualTo($moment) ? $end->addDay() : $end;
    }

    protected function start(): string
    {
        return $this->normalise((string) config('infolink.notifications.quiet_hours_start', '22:00'));
    }

    protected function end(): string
    {
        return $this->normalise((string) config('infolink.notifications.quiet_hours_end', '08:00'));
    }

    protected function normalise(string $time): string
    {
        [$hour, $minute] = array_pad(explode(':', trim($time)), 2, '0');

        return sprintf('%02d:%02d', (int) $hour, (int) $minute);
    }
}
