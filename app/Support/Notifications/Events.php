<?php

namespace App\Support\Notifications;

use App\Models\AttendanceRecord;
use App\Models\CashPayment;
use App\Models\MemberMembership;
use App\Models\OrganizationMembership;
use App\Models\PaymentReversal;
use App\Models\PlatformNotification;
use App\Models\Reservation;
use App\Models\StudioAnnouncement;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Models\WaitingListEntry;
use Illuminate\Support\Facades\DB;
use LogicException;

final class Events
{
    public static function sessionFingerprint(TrainingSession $s): string
    {
        return hash('sha256', json_encode([$s->starts_at->toIso8601String(), $s->ends_at->toIso8601String(), $s->location_id, $s->trainer_profile_id], JSON_THROW_ON_ERROR));
    }

    /** Internal only: typed sources and recipients, never request payloads. Caller holds organization lock.
     * @param  array<string,mixed>  $references
     * @param  array<string,string>  $metadata
     */
    private static function create(NotificationType $type, StudioMember|OrganizationMembership $recipient, string $eventKey, array $references, array $metadata): PlatformNotification
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Notification evidence requires the domain transaction.');
        }
        $column = $recipient instanceof StudioMember ? 'studio_member_id' : 'organization_membership_id';
        $existing = PlatformNotification::query()->where('organization_id', $recipient->organization_id)->where($column, $recipient->id)->where('event_key', $eventKey)->first();
        if ($existing !== null) {
            return $existing;
        }
        $n = new PlatformNotification;
        $n->forceFill($references + Registry::payload($type, $metadata) + ['organization_id' => $recipient->organization_id, $column => $recipient->id, 'event_key' => $eventKey])->save();
        DB::table('notification_deliveries')->insert(['organization_id' => $n->organization_id, 'platform_notification_id' => $n->id, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $n->setRelation($recipient instanceof StudioMember ? 'member' : 'staff', $recipient);
        ExternalChannels::enqueue($n);

        return $n;
    }

    public static function reservation(Reservation $r, NotificationType $type, ?string $eventId = null): PlatformNotification
    {
        if (! in_array($type, [NotificationType::ReservationConfirmed, NotificationType::ReservationCancelled, NotificationType::WaitingPromoted, NotificationType::SessionReminder, NotificationType::SessionChanged, NotificationType::SessionCancelled], true)) {
            throw new LogicException('Invalid reservation notification.');
        }
        $s = $r->session;
        $suffix = $eventId ?? ($type === NotificationType::SessionReminder ? $s->starts_at->toIso8601String() : 'once');

        return self::create($type, $r->member, $type->value.':'.$r->public_id.':'.$suffix, ['reservation_id' => $r->id, 'training_session_id' => $s->id, 'session_starts_at' => $s->starts_at, 'source_fingerprint' => self::sessionFingerprint($s)], ['date' => $s->local_date, 'time' => $s->starts_at->setTimezone($s->timezone)->format('H:i'), 'timezone' => $s->timezone]);
    }

    public static function announcement(StudioAnnouncement $a, StudioMember $member): PlatformNotification
    {
        if ($a->organization_id !== $member->organization_id) {
            throw new LogicException('Foreign announcement recipient.');
        }

        return self::create(NotificationType::StudioAnnouncement, $member, 'studio.announcement:'.$a->public_id, ['announcement_id' => $a->id, 'title_mk' => $a->getAttribute('title_mk'), 'title_en' => $a->getAttribute('title_en'), 'message_mk' => $a->getAttribute('message_mk'), 'message_en' => $a->getAttribute('message_en')], []);
    }

    public static function waiting(WaitingListEntry $entry, NotificationType $type): PlatformNotification
    {
        if (! in_array($type, [NotificationType::WaitingJoined, NotificationType::WaitingExpired, NotificationType::WaitingSessionCancelled], true)) {
            throw new LogicException('Invalid waiting event.');
        }
        $s = TrainingSession::findOrFail($entry->training_session_id);
        $member = StudioMember::findOrFail($entry->studio_member_id);

        return self::create($type, $member, $type->value.':'.$entry->public_id, ['waiting_list_entry_id' => $entry->id, 'training_session_id' => $s->id, 'session_starts_at' => $s->starts_at, 'source_fingerprint' => self::sessionFingerprint($s)], ['date' => $s->local_date, 'time' => $s->starts_at->setTimezone($s->timezone)->format('H:i'), 'timezone' => $s->timezone]);
    }

    public static function membership(MemberMembership $m, NotificationType $type, ?string $suffix = null): PlatformNotification
    {
        if (! in_array($type, [NotificationType::MembershipCreated, NotificationType::MembershipActivated, NotificationType::MembershipExpiring, NotificationType::MembershipExpired], true)) {
            throw new LogicException('Invalid membership notification.');
        }

        return self::create($type, $m->member, $type->value.':'.$m->public_id.':'.($suffix ?? 'once'), ['member_membership_id' => $m->id], ['starts_on' => $m->starts_on, 'ends_on' => $m->ends_on]);
    }

    public static function payment(CashPayment $p, ?PaymentReversal $r = null): PlatformNotification
    {
        if ($r !== null && ($r->organization_id !== $p->organization_id || $r->cash_payment_id !== $p->id)) {
            throw new LogicException('Foreign reversal.');
        }
        $type = $r === null ? NotificationType::CashRecorded : NotificationType::CashReversed;

        return self::create($type, $p->member, $type->value.':'.($r === null ? $p->public_id : $r->public_id), ['member_membership_id' => $p->member_membership_id, 'cash_payment_id' => $p->id, 'payment_reversal_id' => $r?->id], []);
    }

    public static function attendance(AttendanceRecord $a, string $eventId): PlatformNotification
    {
        $r = $a->reservation;
        $s = $r->session;

        return self::create(NotificationType::AttendanceResult, $r->member, 'attendance.result:'.$a->public_id.':'.$eventId, ['attendance_record_id' => $a->id, 'reservation_id' => $r->id, 'training_session_id' => $s->id, 'session_starts_at' => $s->starts_at], ['date' => $s->local_date, 'status' => $a->status]);
    }

    public static function session(TrainingSession $s, bool $cancelled, string $eventId): void
    {
        $type = $cancelled ? NotificationType::SessionCancelled : NotificationType::SessionChanged;
        // Session capacity bounds active recipients. No historical reservation fan-out.
        foreach (Reservation::query()->where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->where('status', 'active')->with(['member', 'session'])->orderBy('id')->get() as $r) {
            self::reservation($r, $type, $eventId);
        }
        if ($cancelled) {
            foreach (WaitingListEntry::where('organization_id', $s->organization_id)->where('training_session_id', $s->id)->where('status', 'waiting')->orderBy('id')->limit(1000)->get() as $entry) {
                self::waiting($entry, NotificationType::WaitingSessionCancelled);
            }
        }
        $staff = $s->trainer->membership;
        $type = $cancelled ? NotificationType::StaffSessionCancelled : NotificationType::StaffSessionChanged;
        self::create($type, $staff, $type->value.':'.$s->public_id.':'.$eventId, ['training_session_id' => $s->id, 'session_starts_at' => $s->starts_at, 'source_fingerprint' => self::sessionFingerprint($s)], ['date' => $s->local_date, 'time' => $s->starts_at->setTimezone($s->timezone)->format('H:i'), 'timezone' => $s->timezone]);
    }
}
