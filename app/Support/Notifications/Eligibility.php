<?php

namespace App\Support\Notifications;

use App\Models\PlatformNotification;
use App\Support\LocationScope;
use App\Support\MembershipStatus;
use App\Support\MemberStatus;
use App\Support\OrganizationStatus;
use App\Support\SessionDirectory;
use Illuminate\Support\Facades\DB;

final class Eligibility
{
    /** @param array<string,array<string,bool>>|null $preferences */
    public static function reason(PlatformNotification $n, ?array $preferences = null): ?string
    {
        $org = $n->organization;
        if ($org->status !== OrganizationStatus::Active) {
            return 'ineligible';
        }
        if ($n->studio_member_id !== null && $n->member?->status !== MemberStatus::Active) {
            return 'ineligible';
        }
        if ($n->organization_membership_id !== null) {
            $staff = $n->staff;
            if ($staff === null || $staff->status !== MembershipStatus::Active || ! in_array('studio.access', $staff->permissions, true) || ! in_array('sessions.view', $staff->permissions, true)) {
                return 'ineligible';
            }
            $s = $n->session ?? throw new \LogicException('Missing notification session');
            if ($s->trainer->organization_membership_id !== $staff->id) {
                return 'ineligible';
            }
            if ($staff->location_scope === LocationScope::Selected && ! $staff->locations->contains('id', $s->location_id)) {
                return 'ineligible';
            }
        }
        $type = NotificationType::from($n->type);
        if ($type->optional()) {
            $field = $type === NotificationType::SessionReminder ? 'reminders_enabled' : 'expiry_reminders_enabled';
            $preference = $preferences === null ? DB::table('notification_preferences')->where('organization_id', $org->id)->where('recipient_key', $n->recipient_key)->value($field) : ($preferences[$n->recipient_key][$field] ?? true);
            if ($preference === false) {
                return 'preference';
            }
        }
        if ($n->training_session_id !== null) {
            $s = $n->session ?? throw new \LogicException('Missing notification session');
            $r = $n->reservation;
            if (in_array($type, [NotificationType::SessionCancelled, NotificationType::StaffSessionCancelled], true)) {
                return $s->status === 'cancelled' ? null : 'ineligible';
            }
            if ($type === NotificationType::ReservationCancelled) {
                return $r?->status === 'cancelled' ? null : 'ineligible';
            }
            if ($type === NotificationType::AttendanceResult) {
                $a = $n->attendance ?? throw new \LogicException('Missing attendance source');

                return $a->status === ($n->metadata['status'] ?? null) ? null : 'ineligible';
            }
            if ($s->status !== 'scheduled' || ! $s->starts_at->isFuture() || ($r !== null && $r->status !== 'active') || SessionDirectory::issues($s) !== [] || Events::sessionFingerprint($s) !== $n->source_fingerprint) {
                return 'ineligible';
            }
        }
        if ($n->member_membership_id !== null) {
            $m = $n->membership ?? throw new \LogicException('Missing membership source');
            if (in_array($type, [NotificationType::CashRecorded, NotificationType::CashReversed], true)) {
                // Reversal supersedes an undelivered receipt; a reversal notice remains useful after cancellation.
                return $type === NotificationType::CashRecorded && $n->cash?->reversal !== null ? 'ineligible' : null;
            }
            if ($m->cancelled_at !== null) {
                return 'ineligible';
            }
            $today = now($org->timezone)->toDateString();
            if ($type === NotificationType::MembershipExpired) {
                return $m->ends_on < $today ? null : 'ineligible';
            }
            if (in_array($type, [NotificationType::MembershipExpiring, NotificationType::MembershipActivated], true) && ($m->starts_on > $today || $m->ends_on < $today)) {
                return 'ineligible';
            }
            if ($type === NotificationType::MembershipCreated && $m->ends_on < $today) {
                return 'ineligible';
            }
        }

        return null;
    }
}
