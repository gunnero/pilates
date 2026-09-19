<?php

namespace App\Support;

use App\Models\TrainerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class TrainerDirectory
{
    /** @return Builder<TrainerProfile> */
    public static function query(TenantContext $context, User $actor): Builder
    {
        Gate::forUser($actor)->authorize(Permission::TrainersView->value);
        $query = TrainerProfile::query()->where('trainer_profiles.organization_id', $context->organization()->id)
            ->with(['membership:id,public_id,user_id,role,status,location_scope', 'membership.user:id,name']);
        if ($context->membership()->location_scope === LocationScope::Selected) {
            $visible = $context->locations()->where('locations.status', 'active')->select('locations.id');
            // Visibility uses current staff location overlap, even if the profile itself is inactive.
            $query->whereHas('membership', function (Builder $staff) use ($visible): void {
                $staff->where('status', 'active')->where(function (Builder $scope) use ($visible): void {
                    $scope->where(fn (Builder $all) => $all->where('location_scope', 'all')->whereExists($visible->toBase()))
                        ->orWhere(fn (Builder $selected) => $selected->where('location_scope', 'selected')->whereExists(function ($assignment) use ($visible): void {
                            $assignment->selectRaw('1')->from('membership_locations')->whereColumn('organization_membership_id', 'organization_memberships.id')->whereIn('location_id', $visible);
                        }));
                });
            });
        }

        return $query;
    }

    /** @param array{count: int, locations: list<array{id: string, name: string}>}|null $eligibility
     * @return array<string, mixed>
     */
    public static function represent(TrainerProfile $profile, ?array $eligibility, bool $detail = false): array
    {
        $membership = $profile->membership;
        $data = ['id' => $profile->public_id, 'membership_id' => $membership->public_id,
            'display_name' => $profile->display_name ?? $membership->user->name, 'display_name_override' => $profile->display_name,
            'status' => $profile->status->value, 'staff_role' => $membership->role->value, 'staff_status' => $membership->status->value,
            'location_scope' => $membership->location_scope->value, 'eligible_location_count' => $eligibility['count'] ?? 0,
            'eligible_locations' => $eligibility['locations'] ?? [], 'assignable' => ($eligibility['count'] ?? 0) > 0];
        if ($detail) {
            $data['bio'] = $profile->bio;
        }

        return $data;
    }
}
