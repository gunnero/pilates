<?php

namespace App\Support;

use App\Models\TrainingSession;

final class AttendancePolicy
{
    public const EARLY_MINUTES = 30;

    public const MEMBER_LATE_MINUTES = 30;

    public const QR_SECONDS = 90;

    public static function operational(TrainingSession $s): void
    {
        if ($s->status !== 'scheduled') {
            throw new AttendanceConflict('cancelled');
        }
        if (SessionDirectory::issues($s) !== []) {
            throw new AttendanceConflict('unavailable');
        }
    }

    public static function memberWindow(TrainingSession $s, bool $detailed = false): void
    {
        self::operational($s);
        if (now()->lt($s->starts_at->subMinutes(self::EARLY_MINUTES))) {
            throw new AttendanceConflict($detailed ? 'too_early' : 'window');
        }
        if (($detailed ? now()->gte($s->starts_at->addMinutes(self::MEMBER_LATE_MINUTES)) : now()->gt($s->starts_at->addMinutes(self::MEMBER_LATE_MINUTES))) || now()->gte($s->ends_at)) {
            throw new AttendanceConflict($detailed ? 'too_late' : 'window');
        }
    }

    public static function staffWindow(TrainingSession $s, string $status, bool $correct): void
    {
        self::operational($s);
        if ($status !== 'present' && now()->lt($s->ends_at)) {
            throw new AttendanceConflict('not_ended');
        }
        if ($status === 'present' && (now()->lt($s->starts_at->subMinutes(self::EARLY_MINUTES)) || (now()->gte($s->ends_at) && ! $correct))) {
            throw new AttendanceConflict('window');
        }
    }
}
