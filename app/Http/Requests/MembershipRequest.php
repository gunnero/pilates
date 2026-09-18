<?php

namespace App\Http\Requests;

use App\Support\LocationScope;
use App\Support\MembershipStatus;
use App\Support\Permission;
use App\Support\StaffRole;
use Illuminate\Validation\Rule;

class MembershipRequest extends LifecycleRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['role' => ['sometimes', Rule::enum(StaffRole::class)], 'status' => ['sometimes', Rule::enum(MembershipStatus::class)],
            'permissions' => ['sometimes', 'array', 'max:14'], 'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
            'location_scope' => ['sometimes', Rule::enum(LocationScope::class)], 'location_ids' => ['sometimes', 'array', 'max:20'],
            'location_ids.*' => ['required', 'uuid', 'distinct']];
    }
}
