<?php

namespace Tests\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\StaffRole;

trait OrganizationFixtures
{
    protected function membership(User $user, Organization $organization, StaffRole $role = StaffRole::Owner, ?array $permissions = null, string $scope = 'all'): OrganizationMembership
    {
        $membership = new OrganizationMembership;
        $membership->forceFill(['user_id' => $user->id, 'organization_id' => $organization->id, 'role' => $role, 'permissions' => $permissions ?? $role->permissions(), 'status' => 'active', 'location_scope' => $scope])->save();

        return $membership;
    }

    protected function location(Organization $organization, string $name = 'Centar'): Location
    {
        $location = new Location;
        $location->forceFill(['organization_id' => $organization->id, 'name' => $name, 'status' => 'active'])->save();

        return $location;
    }

    protected function base(Organization $organization): string
    {
        return '/api/v1/organizations/'.$organization->public_id;
    }
}
