<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class Lifecycle
{
    /** Recheck current authority after acquiring the common organization lock.
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function run(TenantContext $context, User $actor, Permission $permission, Closure $operation): mixed
    {
        $publicId = $context->organization()->public_id;

        return DB::transaction(function () use ($context, $actor, $permission, $operation, $publicId) {
            Organization::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $context->resolve($actor, $publicId);
            Gate::forUser($actor)->authorize(Permission::StudioAccess->value);
            Gate::forUser($actor)->authorize($permission->value);

            return $operation();
        }, 3);
    }
}
