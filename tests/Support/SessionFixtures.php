<?php

namespace Tests\Support;

use App\Models\Organization;
use App\Models\ScheduleRule;
use App\Models\TrainerProfile;
use App\Models\TrainingGroup;
use App\Models\User;

trait SessionFixtures
{
    use OrganizationFixtures;

    protected function sessionFixture(): array
    {
        $this->travelTo(now()->setDate(2026, 9, 19)->startOfDay());
        $org = Organization::create(['name' => 'Session fixture']);
        $actor = User::factory()->create();
        $access = $this->membership($actor, $org);
        $location = $this->location($org);
        $trainer = new TrainerProfile;
        $trainer->forceFill(['organization_id' => $org->id, 'organization_membership_id' => $access->id, 'status' => 'active'])->save();
        $group = new TrainingGroup;
        $group->forceFill(['organization_id' => $org->id, 'location_id' => $location->id, 'primary_trainer_id' => $trainer->id, 'name' => 'Pilates', 'capacity' => 12, 'status' => 'active'])->save();
        $rule = new ScheduleRule;
        $rule->forceFill(['organization_id' => $org->id, 'training_group_id' => $group->id, 'weekday' => 1, 'start_minute' => 1080, 'end_minute' => 1140, 'effective_from' => '2026-09-21'])->save();
        $this->actingAs($actor);

        return [$org, $actor, $access, $location, $trainer, $group, $rule];
    }

    protected function sessionData($group, $location, $trainer, array $extra = []): array
    {
        return [...['group_id' => $group->public_id, 'location_id' => $location->public_id, 'trainer_id' => $trainer->public_id, 'capacity' => 12, 'local_date' => '2026-09-22', 'start_time' => '18:00', 'end_time' => '19:00'], ...$extra];
    }
}
