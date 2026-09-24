<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudioSettingsRequest;
use App\Models\Organization;
use App\Models\StudioSetting;
use App\Models\User;
use App\Support\Audit;
use App\Support\Lifecycle;
use App\Support\Permission;
use App\Support\StudioSettings;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

final class StudioSettingsController
{
    /** @return array<string,mixed> */
    private static function represent(Organization $organization): array
    {
        $row = StudioSetting::where('organization_id', $organization->id)->first();
        $overrides = [];
        foreach (StudioSettings::catalog() as $key => $definition) {
            $overrides[$key] = $row?->getAttribute($key);
        }

        return ['effective' => StudioSettings::fromRow($row)->values(), 'overrides' => $overrides, 'catalog' => StudioSettings::catalog()];
    }

    public function show(TenantContext $context): JsonResponse
    {
        return response()->json(['data' => self::represent($context->organization())]);
    }

    public function update(StudioSettingsRequest $request, TenantContext $context): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $data = Lifecycle::run($context, $actor, Permission::OrganizationsManage, function () use ($request, $context, $actor) {
            $org = $context->organization();
            $row = StudioSetting::where('organization_id', $org->id)->first() ?? new StudioSetting;
            $effectiveBefore = StudioSettings::fromRow($row)->values();
            $before = $row->only(array_keys(StudioSettings::catalog()));
            $row->forceFill($request->validated());
            $policy = StudioSettings::fromRow($row);
            if (2 * $policy->qr_rotation_seconds > $policy->qr_lifetime_seconds) {
                throw ValidationException::withMessages(['qr_rotation_seconds' => __('settings.qr_overlap')]);
            }
            $changed = array_intersect_key($row->getDirty(), StudioSettings::catalog());
            if ($changed !== []) {
                $row->forceFill(['organization_id' => $org->id])->save();
                Audit::record($org, $actor, 'studio.settings_changed', 'organization', $org->public_id, ['before' => array_intersect_key($before, $changed), 'after' => $row->only(array_keys($changed)), 'effective_before' => array_intersect_key($effectiveBefore, $changed), 'effective_after' => array_intersect_key($policy->values(), $changed)]);
            }

            return self::represent($org);
        });

        return response()->json(['data' => $data]);
    }
}
