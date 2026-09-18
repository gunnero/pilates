<?php

namespace App\Http\Controllers;

use App\Http\Requests\LocationRequest;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit;
use App\Support\Lifecycle;
use App\Support\LocationScope;
use App\Support\Permission;
use App\Support\Representations;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LocationController
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = $context->locations();
        if (! Gate::allows(Permission::LocationsManage->value)) {
            $query->where('status', 'active');
        }
        $page = $query->orderBy('id')->paginate($data['per_page'] ?? 25);

        return response()->json(['data' => $page->getCollection()->map(fn (Location $location) => Representations::location($location)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(TenantContext $context, string $organization, string $location): JsonResponse
    {
        $query = $context->locations();
        if (! Gate::allows(Permission::LocationsManage->value)) {
            $query->where('status', 'active');
        }

        return response()->json(['data' => Representations::location($query->where('public_id', $location)->firstOrFail())]);
    }

    public function store(LocationRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $location = Lifecycle::run($context, $actor, Permission::LocationsManage, function () use ($context, $request, $actor) {
            abort_unless($context->membership()->location_scope === LocationScope::All, 403);
            $location = new Location;
            $location->forceFill([...$request->validated(), 'organization_id' => $context->organization()->id, 'status' => $request->validated('status', 'active')])->save();
            Audit::record($context->organization(), $actor, 'location.created', 'location', $location->public_id, ['after' => Representations::location($location)]);

            return $location;
        });

        return response()->json(['data' => Representations::location($location)], 201);
    }

    public function update(LocationRequest $request, TenantContext $context, string $organization, string $location): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $record = Lifecycle::run($context, $actor, Permission::LocationsManage, function () use ($context, $request, $actor, $location) {
            $record = $context->locations()->where('public_id', $location)->firstOrFail();
            $before = Representations::location($record);
            $record->forceFill($request->validated());
            $changes = $record->getDirty();
            if ($changes !== []) {
                $record->save();
                $after = Representations::location($record);
                Audit::record($context->organization(), $actor, 'location.updated', 'location', $record->public_id,
                    ['before' => array_intersect_key($before, $changes), 'after' => array_intersect_key($after, $changes)]);
                if (array_key_exists('status', $changes)) {
                    Audit::record($context->organization(), $actor, 'location.status_changed', 'location', $record->public_id, ['before' => $before['status'], 'after' => $after['status']]);
                }
            }

            return $record;
        });

        return response()->json(['data' => Representations::location($record)]);
    }
}
