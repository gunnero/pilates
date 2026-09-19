<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrganizationRequest;
use App\Models\ScheduleRule;
use App\Models\User;
use App\Support\Audit;
use App\Support\Lifecycle;
use App\Support\Permission;
use App\Support\Representations;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationController
{
    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(['data' => Representations::organization($context->organization())]);
    }

    public function update(OrganizationRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $organization = Lifecycle::run($context, $actor, Permission::OrganizationsManage, function () use ($context, $actor, $request) {
            $organization = $context->organization();
            $before = Representations::organization($organization);
            $organization->forceFill($request->validated());
            $changes = $organization->getDirty();
            if (array_key_exists('timezone', $changes)) {
                abort_if(ScheduleRule::query()->where('organization_id', $organization->id)->exists(), 409, __('groups.timezone_locked'));
            }
            if ($changes !== []) {
                $organization->save();
                $after = Representations::organization($organization);
                $keys = array_keys($changes);
                Audit::record($organization, $actor, 'organization.settings_changed', 'organization', $organization->public_id,
                    ['before' => array_intersect_key($before, $changes), 'after' => array_intersect_key($after, $changes)]);
                if (in_array('status', $keys, true)) {
                    Audit::record($organization, $actor, 'organization.status_changed', 'organization', $organization->public_id, ['before' => $before['status'], 'after' => $after['status']]);
                }
            }

            return $organization;
        });

        return response()->json(['data' => Representations::organization($organization)]);
    }

    public function switch(Request $request, TenantContext $context): JsonResponse
    {
        // The selected UUID is the already-authorized route parameter, never an unchecked body field.
        abort_unless($request->all() === [], 422);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Lifecycle::run($context, $actor, Permission::StudioAccess, function () use ($context, $actor, $request): void {
            Audit::record($context->organization(), $actor, 'organization.switched', 'organization', $context->organization()->public_id,
                ['channel' => $request->hasSession() ? 'web' : 'token']);
            if ($request->hasSession()) {
                $request->session()->put('active_organization_id', $context->organization()->public_id);
            }
        });

        return response()->json(['data' => ['id' => $context->organization()->public_id]]);
    }
}
