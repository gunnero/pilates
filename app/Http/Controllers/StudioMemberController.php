<?php

namespace App\Http\Controllers;

use App\Http\Requests\MemberSearchRequest;
use App\Http\Requests\MemberStatusRequest;
use App\Http\Requests\StudioMemberRequest;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\MemberContact;
use App\Support\MemberDirectory;
use App\Support\MemberLifecycle;
use App\Support\MemberStatus;
use App\Support\Permission;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudioMemberController
{
    public function index(MemberSearchRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $query = MemberDirectory::query($context, $actor);
        $data = $request->validated();
        if (! isset($data['status'])) {
            $query->where('status', '<>', 'archived');
        } elseif ($data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        if (isset($data['digital_access_mode'])) {
            $query->where('digital_access_mode', $data['digital_access_mode']);
        }
        if (isset($data['preferred_location_id'])) {
            Gate::forUser($actor)->authorize(Permission::LocationsView->value);
            $location = $context->locations()->where('public_id', $data['preferred_location_id'])->firstOrFail();
            $query->where('preferred_location_id', $location->id);
        }
        if (! empty($data['search'])) {
            $term = addcslashes(mb_strtolower($data['search']), '\\%_').'%';
            $phone = addcslashes(MemberContact::phone($data['search']), '\\%_').'%';
            $query->where(fn (Builder $search) => $search->whereRaw('lower(first_name) LIKE ?', [$term])->orWhereRaw('lower(last_name) LIKE ?', [$term])->orWhereRaw("lower(first_name || ' ' || last_name) LIKE ?", [$term])->orWhere('email', 'like', $term)->orWhere('phone', 'like', $phone));
        }
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);

        return response()->json(['data' => $page->getCollection()->map(fn (StudioMember $member) => MemberDirectory::represent($member, $actor)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, TenantContext $context, string $organization, string $member): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = MemberDirectory::query($context, $actor, true)->where('public_id', $member)->firstOrFail();

        return response()->json(['data' => MemberDirectory::represent($record, $actor, true)]);
    }

    public function store(StudioMemberRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $this->saved(MemberLifecycle::save($context, $actor, null, $request->validated()), $actor, 201);
    }

    public function update(StudioMemberRequest $request, TenantContext $context, string $organization, string $member): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $this->saved(MemberLifecycle::save($context, $actor, $member, $request->validated()), $actor);
    }

    public function status(MemberStatusRequest $request, TenantContext $context, string $organization, string $member): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = MemberLifecycle::status($context, $actor, $member, MemberStatus::from($request->validated('status')));

        return response()->json(['data' => MemberDirectory::represent($record, $actor, true)]);
    }

    private function saved(StudioMember $member, User $actor, int $status = 200): JsonResponse
    {
        return response()->json(['data' => MemberDirectory::represent($member, $actor, true), 'meta' => ['duplicate_warning' => MemberDirectory::duplicateWarning($member)]], $status);
    }
}
