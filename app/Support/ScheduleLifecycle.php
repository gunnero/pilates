<?php

namespace App\Support;

use App\Models\ScheduleRule;
use App\Models\TrainingGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class ScheduleLifecycle
{
    private static function group(TenantContext $context, User $actor, string $id): TrainingGroup
    {
        Gate::forUser($actor)->authorize(Permission::ScheduleView->value);
        $group = GroupDirectory::query($context, $actor)->where('public_id', $id)->firstOrFail();
        abort_if($group->status === 'archived', 409, __('groups.archived'));

        return $group;
    }

    /** @param array<string, mixed> $data */
    public static function create(TenantContext $context, User $actor, string $groupId, array $data, ?string $replaceId = null): ScheduleRule
    {
        return Lifecycle::run($context, $actor, Permission::ScheduleManage, function () use ($context, $actor, $groupId, $data, $replaceId) {
            $group = self::group($context, $actor, $groupId);
            GroupLifecycle::assertAssignment($context, $group);
            $today = GroupLifecycle::today($context);
            $minute = static function (string $value): int {
                [$h, $m] = explode(':', $value);

                return (int) $h * 60 + (int) $m;
            };
            $start = $minute($data['start_time']);
            $end = $minute($data['end_time']);
            if ($start >= $end || $end - $start > 240) {
                throw ValidationException::withMessages(['end_time' => __('groups.duration')]);
            }
            if ($data['effective_from'] < $today) {
                throw ValidationException::withMessages(['effective_from' => __('groups.future')]);
            }
            $old = null;
            $reconcile = false;
            if ($replaceId !== null) {
                $old = $group->scheduleRules()->where('public_id', $replaceId)->firstOrFail();
                abort_if($group->scheduleRules()->where('replaces_rule_id', $old->id)->exists(), 409, __('groups.already_replaced'));
                if ($old->canceled_at !== null || $data['effective_from'] <= max($today, $old->effective_from) || ($old->effective_until !== null && $data['effective_from'] > $old->effective_until)) {
                    throw ValidationException::withMessages(['effective_from' => __('groups.replacement')]);
                }
                $oldEnd = $old->effective_until;
                $old->effective_until = CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString();
                $reconcile = SessionReconciliation::close($context, $actor, $old, $old->effective_until);
                $old->save();
                Audit::record($context->organization(), $actor, 'schedule.rule_closed', 'schedule_rule', $old->public_id, ['group_id' => $group->public_id, 'before' => $oldEnd, 'after' => $old->effective_until]);
            }
            $rule = new ScheduleRule;
            $rule->forceFill(['organization_id' => $group->organization_id, 'training_group_id' => $group->id,
                'weekday' => $data['weekday'], 'start_minute' => $start, 'end_minute' => $end, 'effective_from' => $data['effective_from'],
                'effective_until' => $data['effective_until'] ?? null, 'replaces_rule_id' => $old?->id]);
            ScheduleConflicts::assertRule($group, $rule);
            $rule->save();
            Audit::record($context->organization(), $actor, $old === null ? 'schedule.rule_created' : 'schedule.rule_replaced', 'schedule_rule', $rule->public_id,
                ['group_id' => $group->public_id, 'replaces_id' => $old?->public_id, 'weekday' => $rule->weekday, 'start_time' => $data['start_time'], 'end_time' => $data['end_time'], 'effective_from' => $rule->effective_from, 'effective_until' => $rule->effective_until]);

            if ($reconcile) {
                $result = SessionMaterializer::run($context->organization()->id, $group->public_id, $context, $actor);
                if ($result['invalid'] > 0 || $result['dst'] > 0 || $result['conflict'] > 0 || $result['limit'] > 0) {
                    throw new SessionConflict('reconciliation_failed');
                }
            }

            return $rule;
        });
    }

    public static function close(TenantContext $context, User $actor, string $groupId, string $ruleId, ?string $until): void
    {
        Lifecycle::run($context, $actor, Permission::ScheduleManage, function () use ($context, $actor, $groupId, $ruleId, $until): void {
            $group = self::group($context, $actor, $groupId);
            $rule = $group->scheduleRules()->where('public_id', $ruleId)->firstOrFail();
            abort_if($rule->canceled_at !== null, 409);
            $today = GroupLifecycle::today($context);
            $before = $rule->effective_until;
            if ($until === null) {
                if ($rule->effective_from <= $today) {
                    throw ValidationException::withMessages(['rule' => __('groups.cancel_future')]);
                }
                $rule->canceled_at = now();
            } else {
                if ($until < max($today, $rule->effective_from) || ($before !== null && $until > $before)) {
                    throw ValidationException::withMessages(['effective_until' => __('groups.close_future')]);
                }
                if ($until === $before) {
                    return;
                }
                $rule->effective_until = $until;
            }
            SessionReconciliation::close($context, $actor, $rule, $until);
            $rule->save();
            Audit::record($context->organization(), $actor, $until === null ? 'schedule.rule_canceled' : 'schedule.rule_closed', 'schedule_rule', $rule->public_id,
                ['group_id' => $group->public_id, 'before' => $before, 'after' => $until]);
        });
    }

    /** @return array<string, mixed> */
    public static function represent(ScheduleRule $rule): array
    {
        $time = static fn (int $minutes): string => sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);

        return ['id' => $rule->public_id, 'weekday' => $rule->weekday, 'start_time' => $time($rule->start_minute), 'end_time' => $time($rule->end_minute),
            'effective_from' => $rule->effective_from, 'effective_until' => $rule->effective_until, 'canceled' => $rule->canceled_at !== null];
    }
}
