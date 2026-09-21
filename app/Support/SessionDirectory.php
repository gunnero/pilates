<?php

namespace App\Support;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

final class SessionDirectory
{
    /** @return Builder<TrainingSession> */
    public static function query(TenantContext $context, User $actor): Builder
    {
        Gate::forUser($actor)->authorize(Permission::SessionsView->value);
        $q = TrainingSession::query()->where('organization_id', $context->organization()->id)->whereIn('location_id', $context->locations()->select('locations.id'));
        if ($context->membership()->role === StaffRole::Trainer) {
            $q->whereHas('trainer', fn (Builder $t) => $t->where('organization_membership_id', $context->membership()->id));
        }

        return $q->withCount(['reservations as reserved_count' => fn (Builder $q) => $q->where('status', 'active'), 'waitingEntries as waiting_count' => fn (Builder $q) => $q->where('status', 'waiting')])->with(['group', 'location', 'trainer.membership.locations']);
    }

    /** Snapshot assignment readiness, never current group defaults.
     * @return list<string>
     */
    public static function issues(TrainingSession $s): array
    {
        $issues = [];
        if ($s->group->status !== 'active') {
            $issues[] = 'group_'.$s->group->status;
        }
        if ($s->location->status !== LocationStatus::Active) {
            $issues[] = 'location_inactive';
        }
        $m = $s->trainer->membership;
        if ($s->trainer->status !== TrainerStatus::Active || $m->status !== MembershipStatus::Active || ($m->location_scope === LocationScope::Selected && ! $m->locations->contains('id', $s->location_id))) {
            $issues[] = 'trainer_unavailable';
        }

        return $issues;
    }

    /** @return array<string,mixed> */
    public static function represent(TrainingSession $s): array
    {
        if ($s->reserved_count > $s->capacity) {
            Log::error('reservation_capacity_violation', ['session_id' => $s->public_id]);
        }

        return ['id' => $s->public_id, 'group' => ['id' => $s->group->public_id, 'name' => $s->group_name], 'location' => ['id' => $s->location->public_id, 'name' => $s->location_name], 'trainer' => ['id' => $s->trainer->public_id, 'display_name' => $s->trainer_name], 'local_date' => $s->local_date, 'start_time' => $s->starts_at->setTimezone($s->timezone)->format('H:i'), 'end_time' => $s->ends_at->setTimezone($s->timezone)->format('H:i'), 'starts_at' => $s->starts_at->utc()->toIso8601String(), 'ends_at' => $s->ends_at->utc()->toIso8601String(), 'timezone' => $s->timezone, 'capacity' => $s->capacity, 'reserved_count' => $s->reserved_count, 'available_spots' => max(0, $s->capacity - $s->reserved_count), 'waiting_count' => $s->waiting_count, 'status' => $s->status, 'source_type' => $s->source_type, 'is_overridden' => $s->is_overridden, 'cancellation_reason' => $s->cancellation_reason, 'cancellation_kind' => $s->cancellation_kind, 'operational_issues' => self::issues($s), 'editable' => $s->status === 'scheduled' && $s->starts_at->isFuture()];
    }
}
