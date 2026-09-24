<?php

namespace App\Support\Notifications;

enum NotificationType: string
{
    case ReservationConfirmed = 'reservation.confirmed';
    case ReservationCancelled = 'reservation.cancelled';
    case WaitingPromoted = 'waiting.promoted';
    case SessionCancelled = 'session.cancelled';
    case SessionChanged = 'session.changed';
    case SessionReminder = 'session.reminder';
    case AttendanceResult = 'attendance.result';
    case MembershipCreated = 'membership.created';
    case MembershipActivated = 'membership.activated';
    case MembershipExpiring = 'membership.expiring';
    case MembershipExpired = 'membership.expired';
    case CashRecorded = 'cash.recorded';
    case CashReversed = 'cash.reversed';
    case StaffSessionChanged = 'staff.session_changed';
    case StaffSessionCancelled = 'staff.session_cancelled';

    public function optional(): bool
    {
        return in_array($this, [self::SessionReminder, self::MembershipExpiring], true);
    }
}
