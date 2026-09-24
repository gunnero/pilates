<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationListRequest;
use App\Http\Requests\SelfProfileRequest;
use App\Http\Requests\SelfReservationRequest;
use App\Http\Requests\SelfServiceListRequest;
use App\Models\AttendanceRecord;
use App\Models\Reservation;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\WaitingListEntry;
use App\Support\Allocation;
use App\Support\Audit;
use App\Support\ReservationConflict;
use App\Support\SelfService\Directory as D;
use App\Support\SelfService\MemberContext;
use App\Support\StudioSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MemberSelfServiceController
{
    private function context(Request $r, string $organization): MemberContext
    {
        return MemberContext::resolve(MemberAccountController::actor($r), $organization);
    }

    /** @param LengthAwarePaginator<int,mixed> $p @param \Closure(mixed):array<string,mixed> $represent */
    private function page(LengthAwarePaginator $p, \Closure $represent): JsonResponse
    {
        return response()->json(['data' => array_map($represent, $p->items()), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }

    public function settings(Request $r, string $organization): JsonResponse
    {
        $c = $this->context($r, $organization);
        $policy = StudioSettings::forOrganization($c->organization);

        return response()->json(['data' => array_intersect_key($policy->values(), array_flip(['self_reservation_enabled', 'self_cancellation_enabled', 'self_check_in_enabled', 'waiting_list_enabled', 'reservation_horizon_days', 'reservation_cutoff_minutes', 'cancellation_cutoff_minutes', 'check_in_early_minutes', 'check_in_late_minutes'])) + ['locale' => $c->member->preferred_locale ?? $c->organization->locale ?? config('app.locale')]]);
    }

    public function profile(Request $r, string $organization): JsonResponse
    {
        return response()->json(['data' => D::profile($this->context($r, $organization)->member)]);
    }

    public function updateProfile(SelfProfileRequest $r, string $organization): JsonResponse
    {
        $data = MemberContext::mutate(MemberAccountController::actor($r), $organization, function (MemberContext $c) use ($r) {
            $m = $c->member;
            $m->forceFill($r->validated());
            $fields = array_keys($m->getDirty());
            if ($fields !== []) {
                $m->save();
                Audit::record($c->organization, $c->user, 'member.self_profile_changed', 'studio_member', $m->public_id, ['fields' => $fields]);
            }

            return D::profile($m);
        });

        return response()->json(['data' => $data]);
    }

    public function groups(ReservationListRequest $r, string $organization, ?string $group = null): JsonResponse
    {
        $c = $this->context($r, $organization);
        $q = TrainingGroup::where('organization_id', $c->organization->id)->where('status', 'active')->whereHas('location', fn ($q) => $q->where('status', 'active'))->with(['location', 'primaryTrainer.membership.user', 'scheduleRules' => fn ($q) => $q->whereNull('canceled_at')->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', now($c->organization->timezone)->toDateString()))->orderBy('effective_from')->limit(56)]);
        $represent = fn ($g) => ['id' => $g->public_id, 'name' => $g->name, 'description' => $g->description, 'capacity' => $g->capacity, 'location' => ['id' => $g->location->public_id, 'name' => $g->location->name], 'trainer' => $g->primaryTrainer === null ? null : ['id' => $g->primaryTrainer->public_id, 'name' => $g->primaryTrainer->display_name ?? $g->primaryTrainer->membership->user->name], 'timezone' => $c->organization->timezone, 'schedule' => $g->scheduleRules->map(fn ($s) => ['weekday' => $s->weekday, 'start_minute' => $s->start_minute, 'end_minute' => $s->end_minute, 'effective_from' => $s->effective_from, 'effective_until' => $s->effective_until])];
        if ($group !== null) {
            return response()->json(['data' => $represent($q->where('public_id', $group)->firstOrFail())]);
        }

        return $this->page($q->orderBy('name')->orderBy('id')->paginate($r->validated('per_page', 25)), $represent);
    }

    public function sessions(SelfServiceListRequest $r, string $organization): JsonResponse
    {
        $c = $this->context($r, $organization);
        $q = D::sessions($c);
        $from = $r->validated('from', now($c->organization->timezone)->toDateString());
        $until = $r->validated('until', CarbonImmutable::parse($from)->addDays(55)->toDateString());
        $q->whereBetween('local_date', [$from, $until]);
        if ($r->filled('group_id')) {
            $q->whereHas('group', fn ($q) => $q->where('public_id', $r->validated('group_id')));
        }
        $relationship = $r->validated('relationship', 'all');
        if ($relationship === 'reserved') {
            $q->whereHas('reservations', fn ($q) => $q->where('studio_member_id', $c->member->id)->where('status', 'active'));
        } elseif ($relationship === 'waiting') {
            $q->whereHas('waitingEntries', fn ($q) => $q->where('studio_member_id', $c->member->id)->where('status', 'waiting'));
        } else {
            $q->where('status', 'scheduled')->where('starts_at', '>', now())->whereHas('group', fn ($q) => $q->where('status', 'active'))->whereHas('location', fn ($q) => $q->where('status', 'active'));
        }

        $policy = StudioSettings::forOrganization($c->organization);

        return $this->page($q->orderBy('starts_at')->orderBy('id')->paginate($r->validated('per_page', 25)), fn ($s) => D::session($s, $policy));
    }

    public function session(Request $r, string $organization, string $session): JsonResponse
    {
        $c = $this->context($r, $organization);
        $s = D::sessions($c)->where('public_id', $session)->where(function ($q) use ($c) {
            $q->where(fn ($q) => $q->where('status', 'scheduled')->whereHas('group', fn ($q) => $q->where('status', 'active'))->whereHas('location', fn ($q) => $q->where('status', 'active')))->orWhereHas('reservations', fn ($q) => $q->where('studio_member_id', $c->member->id))->orWhereHas('waitingEntries', fn ($q) => $q->where('studio_member_id', $c->member->id));
        })->firstOrFail();

        return response()->json(['data' => D::session($s)]);
    }

    public function reserve(SelfReservationRequest $r, string $organization, string $session): JsonResponse
    {
        $result = MemberContext::mutate(MemberAccountController::actor($r), $organization, function (MemberContext $c) use ($r, $session) {
            $s = TrainingSession::where('organization_id', $c->organization->id)->where('public_id', $session)->firstOrFail();
            $result = Allocation::reserve($s, $c->member, $c->user, $r->boolean('allow_waiting'), false, 'member');

            return ['outcome' => $result['outcome'], 'reservation_id' => $result['reservation']?->public_id, 'waiting_entry_id' => $result['waiting_entry']?->public_id];
        });

        return response()->json(['data' => $result]);
    }

    public function reservation(Request $r, string $organization, string $reservation): JsonResponse
    {
        $c = $this->context($r, $organization);
        $row = Reservation::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->where('public_id', $reservation)->firstOrFail();
        $s = D::sessions($c)->whereKey($row->training_session_id)->firstOrFail();

        return response()->json(['data' => D::reservation($row, $s) + ['session' => D::session($s)]]);
    }

    public function cancel(Request $r, string $organization, string $reservation): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $waiting = $r->routeIs('self.waiting.cancel');
        MemberContext::mutate(MemberAccountController::actor($r), $organization, function (MemberContext $c) use ($reservation, $waiting) {
            $policy = StudioSettings::forOrganization($c->organization);
            if (! $policy->self_cancellation_enabled) {
                throw new ReservationConflict('policy');
            }
            if ($waiting) {
                $w = WaitingListEntry::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->where('public_id', $reservation)->firstOrFail();
                Allocation::cancelWaiting($w, $c->user);
            } else {
                $row = Reservation::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->where('public_id', $reservation)->firstOrFail();
                $s = Allocation::lock($row->session);
                if ($row->status !== 'cancelled' && $s->starts_at->isFuture() && ! $policy->canCancel($s)) {
                    throw new ReservationConflict('policy');
                }
                Allocation::cancel($s, $row, $c->user);
            }
        });

        return response()->json(['data' => ['outcome' => 'cancelled']]);
    }

    public function waiting(ReservationListRequest $r, string $organization, ?string $waiting = null): JsonResponse
    {
        $c = $this->context($r, $organization);
        $q = WaitingListEntry::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->with('session');
        $policy = StudioSettings::forOrganization($c->organization);
        $represent = fn ($w) => ['id' => $w->public_id, 'status' => $w->status, 'session_id' => $w->session->public_id, 'session_name' => $w->session->group_name, 'starts_at' => $w->session->starts_at->toISOString(), 'timezone' => $w->session->timezone, 'can_cancel' => $w->status === 'waiting' && $policy->self_cancellation_enabled];
        if ($waiting !== null) {
            return response()->json(['data' => $represent($q->where('public_id', $waiting)->firstOrFail())]);
        }

        return $this->page($q->orderByDesc('id')->paginate($r->validated('per_page', 25)), $represent);
    }

    public function attendance(SelfServiceListRequest $r, string $organization, ?string $attendance = null): JsonResponse
    {
        $c = $this->context($r, $organization);
        $q = AttendanceRecord::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->with('reservation.session');
        if ($r->filled('from')) {
            $q->whereHas('reservation.session', fn ($q) => $q->whereBetween('local_date', [$r->validated('from'), $r->validated('until', CarbonImmutable::parse($r->validated('from'))->addDays(55)->toDateString())]));
        }
        $represent = fn ($a) => ['id' => $a->public_id, 'status' => $a->status, 'method' => $a->check_in_method, 'checked_in_at' => $a->checked_in_at?->toISOString(), 'session' => ['id' => $a->reservation->session->public_id, 'name' => $a->reservation->session->group_name, 'starts_at' => $a->reservation->session->starts_at->toISOString(), 'timezone' => $a->reservation->session->timezone]];
        if ($attendance !== null) {
            return response()->json(['data' => $represent($q->where('public_id', $attendance)->firstOrFail())]);
        }

        return $this->page($q->orderByDesc('id')->paginate($r->validated('per_page', 25)), $represent);
    }

    public function memberships(ReservationListRequest $r, string $organization, ?string $membership = null): JsonResponse
    {
        $c = $this->context($r, $organization);
        $q = D::memberships($c);
        $represent = fn ($m) => D::membership($m, $c);
        if ($membership !== null) {
            return response()->json(['data' => $represent($q->where('public_id', $membership)->firstOrFail())]);
        }

        return $this->page($q->orderByDesc('starts_on')->orderByDesc('id')->paginate($r->validated('per_page', 25)), $represent);
    }

    public function payments(ReservationListRequest $r, string $organization, ?string $payment = null): JsonResponse
    {
        $c = $this->context($r, $organization);
        $q = D::payments($c);
        if ($payment !== null) {
            return response()->json(['data' => D::payment($q->where('public_id', $payment)->firstOrFail())]);
        }

        return $this->page($q->orderByDesc('paid_at')->orderByDesc('id')->paginate($r->validated('per_page', 25)), D::payment(...));
    }
}
