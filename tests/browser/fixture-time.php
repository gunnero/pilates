<?php

use Carbon\CarbonImmutable;

/** Preserve the real check-in window while keeping a fixture on one studio date. */
function fixtureSessionEnd(CarbonImmutable $start, string $timezone): CarbonImmutable
{
    $localStart = $start->setTimezone($timezone);
    $end = $start->addHour();
    if ($end->setTimezone($timezone)->format('Y-m-d H:i:s') <= $localStart->format('Y-m-d H:i:s')) {
        // A fall-back fold can repeat the same wall-clock hour.
        $end = $end->addHour();
    }

    return $end->min($localStart->endOfDay()->startOfSecond()->utc());
}
