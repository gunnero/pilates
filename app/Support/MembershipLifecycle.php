<?php

namespace App\Support;

use App\Models\Location;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MembershipLifecycle
{
    /** @param array<string, mixed> $data */
    public static function update(TenantContext $context, User $actor, string $publicId, array $data): OrganizationMembership
    {
        return Lifecycle::run($context, $actor, Permission::StaffManage, function () use ($context, $actor, $publicId, $data) {
            $target = OrganizationMembership::query()->where('organization_id', $context->organization()->id)->where('public_id', $publicId)->firstOrFail();
            $role = isset($data['role']) ? StaffRole::from($data['role']) : $target->role;
            $status = isset($data['status']) ? MembershipStatus::from($data['status']) : $target->status;
            $scope = isset($data['location_scope']) ? LocationScope::from($data['location_scope']) : $target->location_scope;
            $permissions = $data['permissions'] ?? ($role !== $target->role ? $role->permissions() : $target->permissions);
            if (array_intersect(['role', 'permissions', 'location_scope', 'location_ids'], array_keys($data)) !== []) {
                Gate::forUser($actor)->authorize(Permission::PermissionsManage->value);
            }
            // Control-plane custody check; ordinary business authorization remains permission based.
            if ($target->role === StaffRole::Owner || $role === StaffRole::Owner) {
                abort_unless($context->membership()->role === StaffRole::Owner, 403);
            }
            $authority = $context->permissions($actor);
            abort_unless(array_diff($target->permissions, $authority) === [] && array_diff($permissions, $authority) === [], 403);
            if ($target->status === MembershipStatus::Revoked) {
                throw ValidationException::withMessages(['status' => __('lifecycle.revoked_terminal')]);
            }
            if ($role === StaffRole::Owner && (array_diff(StaffRole::ownerMinimum(), $permissions) !== [] || $scope !== LocationScope::All)) {
                throw ValidationException::withMessages(['role' => __('lifecycle.owner_access')]);
            }
            $currentIds = DB::table('membership_locations')->where('organization_membership_id', $target->id)->pluck('location_id')->all();
            if ($context->membership()->location_scope === LocationScope::Selected) {
                abort_unless($target->location_scope === LocationScope::Selected && $scope === LocationScope::Selected, 403);
                abort_unless($context->locations()->whereIn('id', $currentIds)->count() === count($currentIds), 403);
            }
            if ($scope === LocationScope::All && ! empty($data['location_ids'])) {
                throw ValidationException::withMessages(['location_ids' => __('lifecycle.all_locations')]);
            }
            $newLocations = isset($data['location_ids']) ? $context->locations()->whereIn('public_id', $data['location_ids'])->get() : null;
            // Missing, foreign and unassigned UUIDs produce identical 404 responses.
            if ($newLocations !== null) {
                abort_unless($newLocations->count() === count($data['location_ids']), 404);
            }
            if ($scope === LocationScope::Selected && (($newLocations !== null && $newLocations->isEmpty()) || ($newLocations === null && $currentIds === []))) {
                throw ValidationException::withMessages(['location_ids' => __('lifecycle.selected_required')]);
            }
            if ($target->status === MembershipStatus::Active && $target->role === StaffRole::Owner && ($role !== StaffRole::Owner || $status !== MembershipStatus::Active)) {
                $others = OrganizationMembership::query()->where('organization_id', $context->organization()->id)->where('role', StaffRole::Owner)->where('status', MembershipStatus::Active)->whereKeyNot($target->id)->exists();
                abort_unless($others, 409, __('lifecycle.last_owner'));
            }
            $before = ['role' => $target->role->value, 'status' => $target->status->value, 'permissions' => $target->permissions, 'location_scope' => $target->location_scope->value];
            $target->role = $role;
            $target->status = $status;
            $target->permissions = array_values($permissions);
            $target->location_scope = $scope;
            $target->save();
            $after = ['role' => $role->value, 'status' => $status->value, 'permissions' => $target->permissions, 'location_scope' => $scope->value];
            foreach (['role' => 'staff.role_changed', 'permissions' => 'staff.permissions_changed', 'status' => 'membership.'.$status->value, 'location_scope' => 'staff.location_access_changed'] as $key => $action) {
                if ($before[$key] !== $after[$key]) {
                    if ($key === 'status' && $status === MembershipStatus::Active) {
                        $action = 'membership.reactivated';
                    }
                    Audit::record($context->organization(), $actor, $action, 'organization_membership', $target->public_id, ['before' => $before[$key], 'after' => $after[$key]]);
                }
            }
            if ($newLocations !== null || $scope === LocationScope::All) {
                $ids = $scope === LocationScope::All ? [] : $newLocations->modelKeys();
                sort($ids);
                sort($currentIds);
                if ($ids !== $currentIds) {
                    $oldPublicIds = Location::query()->where('organization_id', $context->organization()->id)->whereIn('id', $currentIds)->pluck('public_id')->all();
                    DB::table('membership_locations')->where('organization_membership_id', $target->id)->delete();
                    foreach ($ids as $id) {
                        DB::table('membership_locations')->insert(['organization_id' => $context->organization()->id, 'organization_membership_id' => $target->id, 'location_id' => $id]);
                    }
                    Audit::record($context->organization(), $actor, 'staff.location_access_changed', 'organization_membership', $target->public_id,
                        ['before' => $oldPublicIds, 'after' => $scope === LocationScope::All ? [] : $newLocations->pluck('public_id')->all()]);
                }
            }

            return $target->load('user');
        });
    }
}
