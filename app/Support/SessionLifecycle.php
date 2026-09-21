<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SessionLifecycle
{
    /** Caller holds organization lock.
     * @return list<string>
     */
    public static function groupIssues(TrainingGroup $group): array
    {
        $s = new TrainingSession;
        $s->setRelation('group', $group);
        $s->setRelation('location', $group->location);
        if ($group->primaryTrainer === null) {
            return ['trainer_unassigned'];
        }
        $s->setRelation('trainer', $group->primaryTrainer);
        $s->location_id = $group->location_id;

        return SessionDirectory::issues($s);
    }

    public static function conflict(TrainingSession $s): bool
    {
        return TrainingSession::query()->where('organization_id', $s->organization_id)->where('status', 'scheduled')->when($s->exists, fn (Builder $q) => $q->where('id', '!=', $s->id))->where('starts_at', '<', $s->ends_at)->where('ends_at', '>', $s->starts_at)->where(fn (Builder $q) => $q->where('trainer_profile_id', $s->trainer_profile_id)->orWhere('training_group_id', $s->training_group_id))->exists();
    }

    /** @param array<string,mixed> $data */
    public static function save(TenantContext $context, User $actor, array $data, ?string $id = null): TrainingSession
    {
        return Lifecycle::run($context, $actor, Permission::SessionsManage, function () use ($context, $actor, $data, $id) {
            Gate::forUser($actor)->authorize(Permission::SessionsView->value);
            $s = $id === null ? new TrainingSession : SessionDirectory::query($context, $actor)->where('public_id', $id)->firstOrFail();
            if ($s->exists) {
                $s = Allocation::lock($s);
                if ($s->status !== 'scheduled' || ! $s->starts_at->isFuture()) {
                    throw new SessionConflict('readonly');
                }
            }
            $group = $s->exists ? $s->group : GroupDirectory::query($context, $actor)->where('public_id', $data['group_id'])->firstOrFail();
            if ($group->status !== 'active') {
                throw new SessionConflict('invalid');
            }
            $location = $context->locations()->where('public_id', $data['location_id'])->firstOrFail();
            $trainer = TrainerProfile::query()->where('organization_id', $context->organization()->id)->where('public_id', $data['trainer_id'])->firstOrFail();
            if ($context->membership()->role === StaffRole::Trainer) {
                abort_unless($trainer->organization_membership_id === $context->membership()->id, 404);
            }
            if (! TrainerEligibility::locations($context, $trainer)->where('locations.id', $location->id)->exists()) {
                throw ValidationException::withMessages(['trainer_id' => __('groups.eligible_trainer')]);
            }
            $timezone = $s->exists ? $s->timezone : $context->organization()->timezone;
            $times = SessionTime::interval($data['local_date'], $data['start_time'], $data['end_time'], $timezone);
            if (! $times['starts_at']->isFuture() || $data['local_date'] > now($timezone)->addDays(365)->toDateString()) {
                throw ValidationException::withMessages(['local_date' => __('sessions.future')]);
            }
            $creating = ! $s->exists;
            $before = $creating ? null : self::evidence($s);
            $s->forceFill($times + ['organization_id' => $group->organization_id, 'training_group_id' => $group->id, 'location_id' => $location->id, 'trainer_profile_id' => $trainer->id, 'timezone' => $timezone, 'local_date' => $data['local_date'], 'capacity' => $data['capacity']]);
            if ($creating) {
                $s->forceFill(['source_type' => 'manual', 'status' => 'scheduled', 'group_name' => $group->name, 'location_name' => $location->name, 'trainer_name' => $trainer->display_name ?? $trainer->membership->user->name]);
            } else {
                if ($s->isDirty('location_id')) {
                    $s->location_name = $location->name;
                }
                if ($s->isDirty('trainer_profile_id')) {
                    $s->trainer_name = $trainer->display_name ?? $trainer->membership->user->name;
                }
                if (! $s->isDirty()) {
                    return $s;
                }
                $s->is_overridden = true;
            }
            if (self::conflict($s)) {
                throw new SessionConflict('conflict');
            }
            if (! $creating && Allocation::count($s) > $s->capacity) {
                throw new ReservationConflict('capacity_conflict');
            }
            $s->save();
            if (! $creating) {
                Allocation::promote($s, $actor);
            }
            Audit::record($context->organization(), $actor, $creating ? 'session.manual_created' : 'session.overridden', 'training_session', $s->public_id, ['before' => $before, 'after' => self::evidence($s)]);

            return $s;
        });
    }

    /** No names, reason text or internal keys.
     * @return array<string,mixed>
     */
    public static function evidence(TrainingSession $s): array
    {
        return ['location_id' => $s->location()->firstOrFail()->public_id, 'trainer_id' => $s->trainer()->firstOrFail()->public_id, 'capacity' => $s->capacity, 'local_date' => $s->local_date, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(), 'timezone' => $s->timezone];
    }

    public static function cancel(TenantContext $context, User $actor, string $id, ?string $reason): TrainingSession
    {
        return Lifecycle::run($context, $actor, Permission::SessionsCancel, function () use ($context, $actor, $id, $reason) {
            $s = SessionDirectory::query($context, $actor)->where('public_id', $id)->firstOrFail();
            if ($s->status === 'cancelled') {
                return $s;
            }
            if (! $s->starts_at->isFuture()) {
                throw new SessionConflict('readonly');
            }
            self::markCancelled($s, 'manual', $reason);
            Audit::record($context->organization(), $actor, 'session.cancelled', 'training_session', $s->public_id, ['reason_provided' => $reason !== null]);

            return $s;
        });
    }

    public static function markCancelled(TrainingSession $s, string $kind, ?string $reason = null): void
    {
        $s->forceFill(['status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_kind' => $kind, 'cancellation_reason' => $reason])->save();
    }
}
