<?php

namespace App\Support;

use App\Models\MemberMembership;
use Carbon\CarbonImmutable;

final class MembershipPeriod
{
    public const EXPIRING_DAYS = 7;

    public static function end(string $start, string $unit, int $count): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC');
        if ($date === null) {
            throw new \InvalidArgumentException('Invalid membership date.');
        }

        return ($unit === 'months' ? $date->addMonthsNoOverflow($count) : $date->addDays($count))->subDay()->toDateString();
    }

    public static function state(MemberMembership $m, string $today): string
    {
        return $m->cancelled_at !== null ? 'cancelled' : ($m->starts_on > $today ? 'upcoming' : ($m->ends_on < $today ? 'expired' : 'active'));
    }
}
