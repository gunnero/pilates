<?php

namespace App\Support;

use App\Models\ScheduleRule;
use App\Models\TrainingGroup;
use Illuminate\Database\Eloquent\Builder;

final class ScheduleConflicts
{
    /** Caller holds organization lock. Periods inclusive; clock intervals half-open.
     * @return Builder<ScheduleRule>
     */
    public static function overlapping(ScheduleRule $rule): Builder
    {
        return ScheduleRule::query()->where('organization_id', $rule->organization_id)->whereNull('canceled_at')
            ->where('weekday', $rule->weekday)->where('start_minute', '<', $rule->end_minute)->where('end_minute', '>', $rule->start_minute)
            ->whereRaw("daterange(effective_from, effective_until, '[]') && daterange(?::date, ?::date, '[]')", [$rule->effective_from, $rule->effective_until]);
    }

    public static function assertRule(TrainingGroup $group, ScheduleRule $rule, ?int $except = null): void
    {
        $query = self::overlapping($rule);
        if ($except !== null) {
            $query->where('id', '!=', $except);
        }
        abort_if((clone $query)->where('training_group_id', $group->id)->exists(), 409, __('groups.overlap'));
        if ($group->status === 'active' && $group->primary_trainer_id !== null) {
            $others = TrainingGroup::query()->where('organization_id', $group->organization_id)->where('primary_trainer_id', $group->primary_trainer_id)->where('status', 'active')->where('id', '!=', $group->id)->select('id');
            // Do not reveal which conflicting group exists outside the actor's location scope.
            abort_if($query->whereIn('training_group_id', $others)->exists(), 409, __('groups.trainer_conflict'));
        }
    }

    public static function assertGroup(TrainingGroup $group, string $today): void
    {
        if ($group->status !== 'active' || $group->primary_trainer_id === null) {
            return;
        }
        foreach ($group->scheduleRules()->whereNull('canceled_at')->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $today))->cursor() as $rule) {
            // A reassignment must not retroactively claim conflicts in elapsed periods.
            $rule->effective_from = max($today, $rule->effective_from);
            self::assertRule($group, $rule, $rule->id);
        }
    }
}
