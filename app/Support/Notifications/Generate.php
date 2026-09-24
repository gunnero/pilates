<?php

namespace App\Support\Notifications;

use App\Models\MemberMembership;
use App\Models\Organization;
use App\Models\Reservation;
use App\Support\MemberStatus;
use App\Support\OrganizationStatus;
use App\Support\SessionDirectory;
use App\Support\StudioSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

final class Generate
{
    /** Bounded candidates; durable anti-joins let repeated batches make progress without cursors.
     * @return array<string,int>
     */
    public static function run(string $organization, int $limit = 100): array
    {
        Validator::make(['limit' => $limit, 'catchup' => config('notifications.reminder_catchup_hours'), 'expiry_catchup' => config('notifications.expiry_catchup_days')], ['limit' => 'integer|min:1|max:200', 'catchup' => 'integer|min:1|max:24', 'expiry_catchup' => 'integer|min:1|max:30'])->validate();
        $org = Organization::query()->where('public_id', $organization)->where('status', 'active')->firstOrFail();
        $counts = ['considered' => 0, 'created' => 0, 'existing_or_ineligible' => 0, 'failed' => 0];
        $policy = StudioSettings::forOrganization($org);
        $upper = now()->addHours($policy->reminder_lead_hours);
        $lower = $upper->copy()->subHours(config('notifications.reminder_catchup_hours'));
        $reservations = Reservation::query()->when(! $policy->session_reminders_enabled, fn ($q) => $q->whereRaw('false'))->where('reservations.organization_id', $org->id)->where('reservations.status', 'active')
            ->join('training_sessions as s', 's.id', '=', 'reservations.training_session_id')->join('studio_members as m', 'm.id', '=', 'reservations.studio_member_id')
            ->where('m.status', 'active')->where('s.status', 'scheduled')->where('s.starts_at', '>', now())->whereBetween('s.starts_at', [$lower, $upper])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('training_groups as g')->whereColumn('g.id', 's.training_group_id')->where('g.status', 'active'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('locations as l')->whereColumn('l.id', 's.location_id')->where('l.status', 'active'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('trainer_profiles as t')->join('organization_memberships as a', 'a.id', '=', 't.organization_membership_id')->whereColumn('t.id', 's.trainer_profile_id')->where('t.status', 'active')->where('a.status', 'active')->where(fn ($q) => $q->where('a.location_scope', 'all')->orWhereExists(fn ($q) => $q->selectRaw('1')->from('membership_locations as ml')->whereColumn('ml.organization_membership_id', 'a.id')->whereColumn('ml.location_id', 's.location_id'))))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('platform_notifications as n')->whereColumn('n.reservation_id', 'reservations.id')->where('n.type', NotificationType::SessionReminder->value)->whereColumn('n.session_starts_at', 's.starts_at'))
            ->orderBy('s.starts_at')->orderBy('reservations.id')->limit($limit)->pluck('reservations.id');
        foreach ($reservations as $id) {
            self::candidate($org, function () use ($id, $org) {
                $current = StudioSettings::forOrganization($org);
                if (! $current->session_reminders_enabled) {
                    return false;
                }
                $upper = now()->addHours($current->reminder_lead_hours);
                $lower = $upper->copy()->subHours(config('notifications.reminder_catchup_hours'));
                $r = Reservation::whereKey($id)->with(['session', 'member'])->firstOrFail();
                $s = $r->session;
                if ($s->starts_at->lt($lower) || $s->starts_at->gt($upper) || $r->status !== 'active' || $s->status !== 'scheduled' || ! $s->starts_at->isFuture() || $r->member->status !== MemberStatus::Active || SessionDirectory::issues($s) !== []) {
                    return false;
                }
                $n = Events::reservation($r, NotificationType::SessionReminder);

                return $n->wasRecentlyCreated;
            }, $counts);
        }
        $today = now($org->timezone)->toDateString();
        $soon = now($org->timezone)->addDays($policy->expiry_warning_days)->toDateString();
        $old = now($org->timezone)->subDays(config('notifications.expiry_catchup_days'))->toDateString();
        foreach ([NotificationType::MembershipExpiring, NotificationType::MembershipExpired, NotificationType::MembershipActivated] as $type) {
            $q = MemberMembership::query()->where('member_memberships.organization_id', $org->id)->whereNull('cancelled_at')->whereHas('member', fn ($q) => $q->where('status', 'active'));
            if ($type === NotificationType::MembershipExpiring) {
                $q->when(! $policy->expiry_warnings_enabled, fn ($q) => $q->whereRaw('false'))->where('starts_on', '<=', $today)->whereBetween('ends_on', [$today, $soon]);
            } elseif ($type === NotificationType::MembershipExpired) {
                $q->where('ends_on', '<', $today)->where('ends_on', '>=', $old);
            } else {
                $q->whereBetween('starts_on', [now($org->timezone)->subDay()->toDateString(), $today])->where('ends_on', '>=', $today)->whereRaw('(created_at AT TIME ZONE ?)::date < starts_on', [$org->timezone]);
            }
            $ids = $q->whereNotExists(fn ($q) => $q->selectRaw('1')->from('platform_notifications as n')->whereColumn('n.member_membership_id', 'member_memberships.id')->where('n.type', $type->value))->orderBy('ends_on')->orderBy('id')->limit($limit)->pluck('id');
            foreach ($ids as $id) {
                self::candidate($org, function () use ($id, $type, $org) {
                    $current = StudioSettings::forOrganization($org);
                    $today = now($org->timezone)->toDateString();
                    $m = MemberMembership::whereKey($id)->firstOrFail();
                    if ($type === NotificationType::MembershipExpiring && (! $current->expiry_warnings_enabled || $m->ends_on > now($org->timezone)->addDays($current->expiry_warning_days)->toDateString())) {
                        return false;
                    }
                    if ($m->cancelled_at !== null || $m->member->status !== MemberStatus::Active) {
                        return false;
                    }
                    if ($type !== NotificationType::MembershipExpired && ($m->starts_on > $today || $m->ends_on < $today)) {
                        return false;
                    }

                    return Events::membership($m, $type)->wasRecentlyCreated;
                }, $counts);
            }
        }

        return $counts;
    }

    /**
     * @param  \Closure():bool  $operation
     * @param  array<string,int>  $counts
     */
    private static function candidate(Organization $org, \Closure $operation, array &$counts): void
    {
        $counts['considered']++;
        try {
            $created = DB::transaction(function () use ($org, $operation) {
                $current = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();

                $org->setRawAttributes($current->getAttributes(), true);

                return $current->status === OrganizationStatus::Active && $operation();
            }, 3);
            $counts[$created ? 'created' : 'existing_or_ineligible']++;
        } catch (Throwable) {
            $counts['failed']++;
        }
    }
}
