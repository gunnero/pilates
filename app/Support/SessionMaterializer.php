<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\TrainingGroup;
use App\Models\TrainingSession;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SessionMaterializer
{
    public const DAYS = 56;

    /** @return array<string,int> */
    public static function run(int $organizationId, string $groupId, ?TenantContext $context = null, ?User $actor = null): array
    {
        return DB::transaction(function () use ($organizationId, $groupId, $context, $actor) {
            $org = Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
            if ($context !== null && $actor !== null) {
                $context->resolve($actor, $org->public_id);
                Gate::forUser($actor)->authorize(Permission::StudioAccess->value);
                Gate::forUser($actor)->authorize(Permission::SessionsView->value);
                Gate::forUser($actor)->authorize(Permission::SessionsManage->value);
                $group = GroupDirectory::query($context, $actor)->where('public_id', $groupId)->firstOrFail();
                $future = TrainingSession::query()->where('training_group_id', $group->id)->where('starts_at', '>', now())->where('local_date', '<=', now($org->timezone)->addDays(self::DAYS - 1)->toDateString())->limit(801)->pluck('id');
                if ($future->count() > 800) {
                    throw new ReservationConflict('limit');
                }
                abort_unless(SessionDirectory::query($context, $actor)->whereIn('id', $future)->count() === $future->count(), 404);
                if ($context->membership()->role === StaffRole::Trainer) {
                    abort_unless($group->primaryTrainer?->organization_membership_id === $context->membership()->id, 404);
                }
            } else {
                $group = TrainingGroup::query()->where('organization_id', $org->id)->where('public_id', $groupId)->firstOrFail();
            }

            return self::generate($org, $group, $actor);
        }, 3);
    }

    /** Caller holds organization lock. Bounded horizon, one group transaction.
     * @return array<string,int>
     */
    public static function generate(Organization $org, TrainingGroup $group, ?User $actor = null): array
    {
        $result = ['considered' => 0, 'created' => 0, 'existing' => 0, 'elapsed' => 0, 'dst' => 0, 'conflict' => 0, 'invalid' => 0, 'limit' => 0];
        if ($org->status !== OrganizationStatus::Active || SessionLifecycle::groupIssues($group) !== []) {
            $result['invalid'] = 1;
            foreach (SessionLifecycle::groupIssues($group) as $reason) {
                $result[$reason] = 1;
            }
            if ($org->status !== OrganizationStatus::Active) {
                $result['organization_unavailable'] = 1;
            }

            return $result;
        }
        $today = CarbonImmutable::now($org->timezone)->startOfDay();
        $last = $today->addDays(self::DAYS - 1)->toDateString();
        $rules = $group->scheduleRules()->whereNull('canceled_at')->where('effective_from', '<=', $last)->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today->toDateString()))->orderBy('id')->limit(101)->get();
        if ($rules->count() > 100) {
            $result['limit'] = 1;

            return $result;
        }
        foreach ($rules as $rule) {
            for ($i = 0; $i < self::DAYS; $i++) {
                $date = $today->addDays($i);
                $local = $date->toDateString();
                if ($date->dayOfWeekIso !== $rule->weekday || $local < $rule->effective_from || ($rule->effective_until !== null && $local > $rule->effective_until)) {
                    continue;
                }
                $result['considered']++;
                if (TrainingSession::query()->where('schedule_rule_id', $rule->id)->where('occurrence_date', $local)->exists()) {
                    $result['existing']++;

                    continue;
                }
                $clock = static fn (int $m): string => sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
                try {
                    $times = SessionTime::interval($local, $clock($rule->start_minute), $clock($rule->end_minute), $org->timezone);
                } catch (ValidationException) {
                    $result['dst']++;

                    continue;
                }
                if (! $times['starts_at']->isFuture()) {
                    $result['elapsed']++;

                    continue;
                }
                $s = new TrainingSession;
                $s->forceFill($times + ['organization_id' => $org->id, 'training_group_id' => $group->id, 'schedule_rule_id' => $rule->id, 'source_type' => 'recurring', 'occurrence_date' => $local, 'local_date' => $local, 'timezone' => $org->timezone, 'location_id' => $group->location_id, 'trainer_profile_id' => $group->primary_trainer_id, 'capacity' => $group->capacity, 'group_name' => $group->name, 'location_name' => $group->location->name, 'trainer_name' => $group->primaryTrainer->display_name ?? $group->primaryTrainer?->membership->user->name, 'status' => 'scheduled']);
                if (SessionLifecycle::conflict($s)) {
                    $result['conflict']++;

                    continue;
                }
                $s->save();
                $result['created']++;
            }
        }
        if ($result['created'] > 0) {
            Audit::record($org, $actor, 'sessions.materialized', 'training_group', $group->public_id, ['from' => $today->toDateString(), 'until' => $last, ...$result]);
        }

        $sync = PermanentAllocation::group($group);
        foreach ($sync as $key => $value) {
            $result['permanent_'.$key] = $value;
        }

        return $result;
    }
}
