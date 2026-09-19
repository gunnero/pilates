<?php

namespace App\Support;

use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class GroupLifecycle
{
    public static function today(TenantContext $context): string
    {
        return now($context->organization()->timezone)->toDateString();
    }

    public static function assertAssignment(TenantContext $context, TrainingGroup $group): void
    {
        $location = $context->locations()->whereKey($group->location_id)->firstOrFail();
        if ($location->status !== LocationStatus::Active) {
            throw ValidationException::withMessages(['location_id' => __('groups.active_location')]);
        }
        if ($group->primary_trainer_id !== null) {
            $trainer = TrainerProfile::query()->where('organization_id', $context->organization()->id)->whereKey($group->primary_trainer_id)->firstOrFail();
            if (! TrainerEligibility::locations($context, $trainer)->where('locations.id', $location->id)->exists()) {
                throw ValidationException::withMessages(['primary_trainer_id' => __('groups.eligible_trainer')]);
            }
        }
    }

    /** @param array<string, mixed> $data */
    public static function save(TenantContext $context, User $actor, array $data, ?string $id = null): TrainingGroup
    {
        return Lifecycle::run($context, $actor, Permission::GroupsManage, function () use ($context, $actor, $data, $id) {
            Gate::forUser($actor)->authorize(Permission::GroupsView->value);
            $group = $id === null ? new TrainingGroup : GroupDirectory::query($context, $actor)->where('public_id', $id)->firstOrFail();
            abort_if($group->status === 'archived', 409, __('groups.archived'));
            $before = ['capacity' => $group->capacity, 'location' => $group->exists ? $group->location->public_id : null, 'trainer' => $group->exists ? $group->primaryTrainer?->public_id : null];
            $values = array_intersect_key($data, array_flip(['name', 'description', 'capacity']));
            if (array_key_exists('location_id', $data)) {
                $values['location_id'] = $context->locations()->where('public_id', $data['location_id'])->firstOrFail()->id;
            }
            if (array_key_exists('primary_trainer_id', $data)) {
                $values['primary_trainer_id'] = $data['primary_trainer_id'] === null ? null : TrainerProfile::query()->where('organization_id', $context->organization()->id)->where('public_id', $data['primary_trainer_id'])->firstOrFail()->id;
            }
            if (! $group->exists) {
                $values += ['organization_id' => $context->organization()->id, 'status' => 'active'];
            }
            $group->forceFill($values);
            $changed = $group->getDirty();
            $assignmentChanged = ! $group->exists || $group->isDirty(['location_id', 'primary_trainer_id']);
            if ($assignmentChanged) {
                self::assertAssignment($context, $group);
            }
            if ($group->exists && $assignmentChanged && $group->scheduleRules()->exists()) {
                Gate::forUser($actor)->authorize(Permission::ScheduleManage->value);
                ScheduleConflicts::assertGroup($group, self::today($context));
            }
            $creating = ! $group->exists;
            if ($changed !== []) {
                $group->save();
                Audit::record($context->organization(), $actor, $creating ? 'group.created' : 'group.profile_updated', 'training_group', $group->public_id, ['fields' => array_keys($changed)]);
                foreach (['capacity' => 'capacity', 'location_id' => 'location', 'primary_trainer_id' => 'trainer'] as $column => $label) {
                    if (! $creating && array_key_exists($column, $changed)) {
                        $after = match ($label) {
                            'capacity' => $group->capacity,
                            'location' => $context->locations()->whereKey($group->location_id)->firstOrFail()->public_id,
                            'trainer' => $group->primary_trainer_id === null ? null : TrainerProfile::query()->whereKey($group->primary_trainer_id)->firstOrFail()->public_id,
                        };
                        Audit::record($context->organization(), $actor, 'group.'.$label.'_changed', 'training_group', $group->public_id, ['before' => $before[$label], 'after' => $after]);
                    }
                }
            }

            return $group;
        });
    }

    public static function status(TenantContext $context, User $actor, string $id, string $status): TrainingGroup
    {
        return Lifecycle::run($context, $actor, Permission::GroupsManage, function () use ($context, $actor, $id, $status) {
            $group = GroupDirectory::query($context, $actor)->where('public_id', $id)->firstOrFail();
            if ($group->status === $status) {
                return $group;
            }
            if ($group->status === 'archived' && $status !== 'inactive') {
                throw ValidationException::withMessages(['status' => __('groups.restore')]);
            }
            $before = $group->status;
            $group->status = $status;
            if ($status === 'active') {
                if ($group->scheduleRules()->exists()) {
                    Gate::forUser($actor)->authorize(Permission::ScheduleManage->value);
                }
                self::assertAssignment($context, $group);
                ScheduleConflicts::assertGroup($group, self::today($context));
            }
            $group->save();
            Audit::record($context->organization(), $actor, 'group.status_changed', 'training_group', $group->public_id, ['before' => $before, 'after' => $status]);

            return $group;
        });
    }
}
