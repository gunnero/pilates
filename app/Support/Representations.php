<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class Representations
{
    /** @return array<string, mixed> */
    public static function organization(Organization $organization): array
    {
        return ['id' => $organization->public_id, 'name' => $organization->name, 'slug' => $organization->slug,
            'status' => $organization->status->value, 'locale' => $organization->locale, 'timezone' => $organization->timezone, 'currency' => $organization->currency];
    }

    /** @return array<string, mixed> */
    public static function location(Location $location): array
    {
        return ['id' => $location->public_id, 'name' => $location->name, 'address' => $location->address, 'city' => $location->city,
            'country_code' => $location->country_code, 'status' => $location->status->value];
    }

    /** @return array<string, mixed> */
    public static function membership(OrganizationMembership $membership, TenantContext $context, User $actor): array
    {
        $data = ['id' => $membership->public_id, 'user' => ['id' => $membership->user->public_id, 'name' => $membership->user->name],
            'role' => $membership->role->value, 'status' => $membership->status->value, 'location_scope' => $membership->location_scope->value];
        if (Gate::forUser($actor)->allows(Permission::PermissionsView->value)) {
            $data['permissions'] = $membership->permissions;
        }
        if (Gate::forUser($actor)->allows(Permission::PermissionsManage->value)) {
            $data['location_ids'] = $context->locations()->whereIn('id', DB::table('membership_locations')->select('location_id')->where('organization_membership_id', $membership->id))->pluck('public_id')->all();
        }

        return $data;
    }
}
