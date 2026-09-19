<?php

namespace App\Support;

use App\Models\OrganizationMembership;
use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class TrainerLifecycle
{
    public static function authorizeManager(TenantContext $context, User $actor): void
    {
        Gate::forUser($actor)->authorize(Permission::TrainersView->value);
        // A global profile cannot be edited by a manager restricted to a subset of its locations.
        abort_unless($context->membership()->location_scope === LocationScope::All, 403);
    }

    /** @param array<string, mixed> $data */
    public static function enable(TenantContext $context, User $actor, array $data): TrainerProfile
    {
        return Lifecycle::run($context, $actor, Permission::TrainersManage, function () use ($context, $actor, $data) {
            self::authorizeManager($context, $actor);
            $membership = OrganizationMembership::query()->where('organization_id', $context->organization()->id)->where('public_id', $data['membership_id'])->firstOrFail();
            if ($membership->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages(['membership_id' => __('trainers.active_staff')]);
            }
            abort_if(TrainerProfile::query()->where('organization_membership_id', $membership->id)->exists(), 409);
            $profile = new TrainerProfile;
            $profile->forceFill(['organization_id' => $context->organization()->id, 'organization_membership_id' => $membership->id,
                'status' => TrainerStatus::Active, 'display_name' => $data['display_name'] ?? null, 'bio' => $data['bio'] ?? null])->save();
            Audit::record($context->organization(), $actor, 'trainer.enabled', 'trainer_profile', $profile->public_id,
                ['membership_id' => $membership->public_id, 'status' => 'active', 'fields' => array_values(array_intersect(array_keys($data), ['display_name', 'bio']))]);

            return $profile;
        });
    }

    /** @param array<string, mixed> $data */
    public static function update(TenantContext $context, User $actor, string $publicId, array $data): TrainerProfile
    {
        return Lifecycle::run($context, $actor, Permission::TrainersManage, function () use ($context, $actor, $publicId, $data) {
            self::authorizeManager($context, $actor);
            $profile = TrainerDirectory::query($context, $actor)->where('public_id', $publicId)->firstOrFail();
            $profile->forceFill($data);
            $fields = array_keys($profile->getDirty());
            if ($fields !== []) {
                $profile->save();
                Audit::record($context->organization(), $actor, 'trainer.profile_updated', 'trainer_profile', $profile->public_id, ['fields' => $fields]);
            }

            return $profile;
        });
    }

    public static function status(TenantContext $context, User $actor, string $publicId, TrainerStatus $status): TrainerProfile
    {
        return Lifecycle::run($context, $actor, Permission::TrainersManage, function () use ($context, $actor, $publicId, $status) {
            self::authorizeManager($context, $actor);
            $profile = TrainerDirectory::query($context, $actor)->where('public_id', $publicId)->firstOrFail();
            if ($status === TrainerStatus::Active && $profile->membership->status !== MembershipStatus::Active) {
                throw ValidationException::withMessages(['status' => __('trainers.active_staff')]);
            }
            if ($profile->status !== $status) {
                $before = $profile->status->value;
                $profile->status = $status;
                $profile->save();
                Audit::record($context->organization(), $actor, $status === TrainerStatus::Active ? 'trainer.activated' : 'trainer.deactivated', 'trainer_profile', $profile->public_id, ['before' => $before, 'after' => $status->value]);
            }

            return $profile;
        });
    }
}
