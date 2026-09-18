<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\MembershipStatus;
use App\Support\OrganizationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $memberships = $user->organizationMemberships()->where('status', MembershipStatus::Active)
            ->whereJsonContains('permissions', 'studio.access')
            ->whereHas('organization', fn (Builder $query) => $query->where('status', OrganizationStatus::Active))
            ->with('organization')->orderBy('id')->get();
        $organizations = $memberships->map(fn ($membership) => ['id' => $membership->organization->public_id, 'name' => $membership->organization->name]);
        $selected = $request->hasSession() ? $request->session()->get('active_organization_id') : null;
        if (! $organizations->contains('id', $selected)) {
            $selected = null;
        }

        return response()->json(['data' => ['id' => $user->public_id, 'name' => $user->name, 'organizations' => $organizations, 'active_organization_id' => $selected]]);
    }
}
