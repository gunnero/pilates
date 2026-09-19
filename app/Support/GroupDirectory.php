<?php

namespace App\Support;

use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class GroupDirectory
{
    /** @return Builder<TrainingGroup> */
    public static function query(TenantContext $context, User $actor): Builder
    {
        Gate::forUser($actor)->authorize(Permission::GroupsView->value);

        return TrainingGroup::query()->where('training_groups.organization_id', $context->organization()->id)
            ->whereIn('location_id', $context->locations()->select('locations.id'))
            ->with(['location', 'primaryTrainer.membership.user:id,name'])
            ->select('training_groups.*')->selectRaw("EXISTS (SELECT 1 FROM trainer_profiles tp JOIN organization_memberships m ON m.id = tp.organization_membership_id WHERE tp.id = training_groups.primary_trainer_id AND tp.organization_id = training_groups.organization_id AND tp.status = 'active' AND m.status = 'active' AND (m.location_scope = 'all' OR EXISTS (SELECT 1 FROM membership_locations ml WHERE ml.organization_membership_id = m.id AND ml.organization_id = training_groups.organization_id AND ml.location_id = training_groups.location_id))) AS trainer_available");
    }

    /** @return array<string, mixed> */
    public static function represent(TrainingGroup $group, TenantContext $context, bool $detail = false): array
    {
        $reasons = [];
        if ($group->status !== 'active') {
            $reasons[] = 'group_'.$group->status;
        }
        if ($group->location->status !== LocationStatus::Active) {
            $reasons[] = 'location_inactive';
        }
        if ($group->primary_trainer_id === null) {
            $reasons[] = 'trainer_unassigned';
        } elseif (! $group->trainer_available) {
            $reasons[] = 'trainer_unavailable';
        }
        $trainer = $group->primaryTrainer;
        $data = ['id' => $group->public_id, 'name' => $group->name, 'capacity' => $group->capacity, 'status' => $group->status,
            'location' => ['id' => $group->location->public_id, 'name' => $group->location->name, 'status' => $group->location->status->value],
            'primary_trainer' => $trainer === null ? null : ['id' => $trainer->public_id, 'display_name' => $trainer->display_name ?? $trainer->membership->user->name],
            'timezone' => $context->organization()->timezone, 'eligible_for_scheduling' => $reasons === [], 'operational_issues' => $reasons];
        if ($detail) {
            $data['description'] = $group->description;
        }

        return $data;
    }
}
