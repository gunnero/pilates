<?php

namespace App\Support\Notifications;

enum NotificationType: string
{
    case ReservationConfirmed = 'reservation.confirmed';
    case ReservationCancelled = 'reservation.cancelled';
    case StudioAnnouncement = 'studio.announcement';
    case WaitingJoined = 'waiting.joined';
    case WaitingSessionCancelled = 'waiting.session_cancelled';
    case WaitingExpired = 'waiting.expired';
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

    public function category(): string
    {
        return match ($this) {
            self::SessionReminder => 'reminders',
            self::WaitingJoined, self::WaitingPromoted, self::WaitingExpired, self::WaitingSessionCancelled => 'waiting_list',
            self::MembershipExpiring, self::MembershipExpired, self::MembershipCreated, self::MembershipActivated => 'membership',
            self::StudioAnnouncement => 'announcements',
            self::SessionChanged, self::SessionCancelled, self::StaffSessionChanged, self::StaffSessionCancelled => 'session_changes',
            default => 'operational',
        };
    }

    public function priority(): string
    {
        return in_array($this, [self::SessionCancelled, self::StaffSessionCancelled, self::WaitingPromoted], true) ? 'high' : 'normal';
    }

    public function optional(): bool
    {
        return in_array($this, [self::SessionReminder, self::MembershipExpiring], true);
    }
}
