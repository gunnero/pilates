<?php

namespace App\Http\Controllers;

use App\Http\Requests\MembershipRequest;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\MembershipLifecycle;
use App\Support\Permission;
use App\Support\Representations;
use App\Support\StaffRole;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $page = OrganizationMembership::query()->where('organization_id', $context->organization()->id)->with('user')->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (OrganizationMembership $membership) => Representations::membership($membership, $context, $actor)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, TenantContext $context, string $organization, string $membership): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = OrganizationMembership::query()->where('organization_id', $context->organization()->id)->where('public_id', $membership)->with('user')->firstOrFail();

        return response()->json(['data' => Representations::membership($record, $context, $actor)]);
    }

    public function update(MembershipRequest $request, TenantContext $context, string $organization, string $membership): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = MembershipLifecycle::update($context, $actor, $membership, $request->validated());

        return response()->json(['data' => Representations::membership($record, $context, $actor)]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => ['permissions' => array_column(Permission::cases(), 'value'),
            'roles' => array_map(fn (StaffRole $role) => ['name' => $role->value, 'permissions' => $role->permissions()], StaffRole::cases())]]);
    }
}
