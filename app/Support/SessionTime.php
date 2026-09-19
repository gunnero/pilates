<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class SessionTime
{
    public static function resolve(string $date, string $time, string $timezone): CarbonImmutable
    {
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, 'UTC');
        if ($wall === null || $wall->format('Y-m-d H:i') !== $date.' '.$time || ! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw ValidationException::withMessages(['time' => __('sessions.time')]);
        }
        $zone = new DateTimeZone($timezone);
        $offsets = array_unique(array_column($zone->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800) ?: [], 'offset'));
        $candidates = [];
        foreach ($offsets as $offset) {
            $instant = $wall->subSeconds($offset);
            if ($instant->setTimezone($timezone)->format('Y-m-d H:i') === $date.' '.$time) {
                $candidates[] = $instant;
            }
        }
        if (count($candidates) !== 1) {
            throw ValidationException::withMessages(['time' => __('sessions.dst')]);
        }

        return $candidates[0];
    }

    /** @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable} */
    public static function interval(string $date, string $start, string $end, string $timezone): array
    {
        $minutes = static fn (string $time): int => (int) substr($time, 0, 2) * 60 + (int) substr($time, 3, 2);
        if ($minutes($end) <= $minutes($start) || $minutes($end) - $minutes($start) > 240) {
            throw ValidationException::withMessages(['end_time' => __('groups.duration')]);
        }
        $a = self::resolve($date, $start, $timezone);
        $b = self::resolve($date, $end, $timezone);
        if ($b <= $a || $a->diffInMinutes($b) > 300) {
            throw ValidationException::withMessages(['time' => __('sessions.time')]);
        }

        return ['starts_at' => $a, 'ends_at' => $b];
    }
}
