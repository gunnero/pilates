<?php

namespace App\Support;

use App\Models\ScheduleRule;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SessionReconciliation
{
    /** Called inside rule mutation's organization transaction, before shortening the rule.
     * Returns whether materialized future sessions were affected.
     */
    public static function close(TenantContext $context, User $actor, ScheduleRule $rule, ?string $until): bool
    {
        $q = TrainingSession::query()->where('organization_id', $context->organization()->id)->where('schedule_rule_id', $rule->id);
        if ($until !== null) {
            $q->where('occurrence_date', '>', $until);
        }
        $affected = $q->get();
        if ($affected->isEmpty()) {
            return false;
        }
        Gate::forUser($actor)->authorize(Permission::SessionsManage->value);
        Gate::forUser($actor)->authorize(Permission::SessionsCancel->value);
        $visible = SessionDirectory::query($context, $actor)->whereIn('id', $affected->modelKeys())->count();
        abort_unless($visible === $affected->count(), 404);
        foreach ($affected as $s) {
            if ($s->is_overridden || $s->cancellation_kind === 'manual' || ! $s->starts_at->isFuture()) {
                throw new SessionConflict('protected');
            }
            if ($s->status === 'scheduled') {
                SessionLifecycle::markCancelled($s, 'schedule');
            }
        }
        Audit::record($context->organization(), $actor, 'sessions.reconciled', 'schedule_rule', $rule->public_id, ['affected' => $affected->count(), 'effective_until' => $until]);

        return true;
    }
}
