<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TenantContext
{
    private ?Organization $organization = null;

    private ?OrganizationMembership $membership = null;

    public function resolve(User $user, string $publicId): void
    {
        $this->clear();
        $membership = $user->organizationMemberships()->where('status', MembershipStatus::Active)
            ->whereHas('organization', fn (Builder $query) => $query->where('public_id', $publicId)->where('status', OrganizationStatus::Active))
            ->with('organization')->firstOrFail();
        $this->organization = $membership->organization;
        $this->membership = $membership;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new LogicException('Tenant context is required.');
    }

    public function membership(): OrganizationMembership
    {
        return $this->membership ?? throw new LogicException('Tenant context is required.');
    }

    public function permits(User $user, Permission $permission): bool
    {
        return $this->membership !== null && $this->membership->user_id === $user->id && in_array($permission->value, $this->membership->permissions, true);
    }

    /** @return list<string> */
    public function permissions(User $user): array
    {
        return array_values(array_filter($this->membership()->permissions, fn (string $permission): bool => $user->currentAccessToken() === null || $user->tokenCan($permission)));
    }

    /** @return Builder<Location> */
    public function locations(): Builder
    {
        $query = Location::query()->where('locations.organization_id', $this->organization()->id);
        if ($this->membership()->location_scope === LocationScope::Selected) {
            $query->whereIn('locations.id', DB::table('membership_locations')->select('location_id')->where('organization_id', $this->organization()->id)->where('organization_membership_id', $this->membership()->id));
        }

        return $query;
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->membership = null;
    }
}
