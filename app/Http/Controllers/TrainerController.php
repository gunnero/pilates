<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrainerRequest;
use App\Http\Requests\TrainerSearchRequest;
use App\Http\Requests\TrainerStatusRequest;
use App\Models\Location;
use App\Models\TrainerProfile;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\TrainerDirectory;
use App\Support\TrainerEligibility;
use App\Support\TrainerLifecycle;
use App\Support\TrainerStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrainerController
{
    public function index(TrainerSearchRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validated();
        $query = TrainerDirectory::query($context, $actor);
        if (isset($data['status']) && $data['status'] !== 'all') {
            $query->where('status', $data['status']);
        }
        if (! empty($data['search'])) {
            $term = addcslashes(mb_strtolower($data['search']), '\\%_').'%';
            $query->where(fn (Builder $name) => $name->whereRaw('lower(display_name) LIKE ?', [$term])->orWhere(fn (Builder $fallback) => $fallback->whereNull('display_name')->whereHas('membership.user', fn (Builder $user) => $user->whereRaw('lower(name) LIKE ?', [$term]))));
        }
        if (isset($data['location_id'])) {
            $location = $context->locations()->where('locations.status', 'active')->where('public_id', $data['location_id'])->firstOrFail();
            $query->where('status', 'active')->whereHas('membership', fn (Builder $staff) => $staff->where('status', 'active')->where(function (Builder $scope) use ($location): void {
                $scope->where('location_scope', 'all')->orWhereExists(function ($assignment) use ($location): void {
                    $assignment->selectRaw('1')->from('membership_locations')->whereColumn('organization_membership_id', 'organization_memberships.id')->where('location_id', $location->id);
                });
            }));
        }
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 25, ['*'], 'page', $data['page'] ?? 1);
        $summaries = TrainerEligibility::summaries($context, $page->getCollection());

        return response()->json(['data' => $page->getCollection()->map(fn (TrainerProfile $profile) => TrainerDirectory::represent($profile, $summaries[$profile->id] ?? null)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, TenantContext $context, string $organization, string $trainer): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $this->profile($context, $actor, $trainer);
    }

    public function store(TrainerRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $profile = TrainerLifecycle::enable($context, $actor, $request->validated());

        return $this->profile($context, $actor, $profile->public_id, 201);
    }

    public function update(TrainerRequest $request, TenantContext $context, string $organization, string $trainer): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        TrainerLifecycle::update($context, $actor, $trainer, $request->validated());

        return $this->profile($context, $actor, $trainer);
    }

    public function status(TrainerStatusRequest $request, TenantContext $context, string $organization, string $trainer): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        TrainerLifecycle::status($context, $actor, $trainer, TrainerStatus::from($request->validated('status')));

        return $this->profile($context, $actor, $trainer);
    }

    public function locations(Request $request, TenantContext $context, string $organization, string $trainer): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $profile = TrainerDirectory::query($context, $actor)->where('public_id', $trainer)->firstOrFail();
        $page = TrainerEligibility::locations($context, $profile)->orderBy('locations.id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (Location $location) => ['id' => $location->public_id, 'name' => $location->name]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    private function profile(TenantContext $context, User $actor, string $publicId, int $status = 200): JsonResponse
    {
        $profile = TrainerDirectory::query($context, $actor)->where('public_id', $publicId)->firstOrFail();
        $summaries = TrainerEligibility::summaries($context, new Collection([$profile]));

        return response()->json(['data' => TrainerDirectory::represent($profile, $summaries[$profile->id] ?? null, true)], $status);
    }
}
