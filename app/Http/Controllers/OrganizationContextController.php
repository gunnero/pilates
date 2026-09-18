<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Representations;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizationContextController
{
    public function __invoke(TenantContext $context, Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => [...Representations::organization($context->organization()),
            'permissions' => $context->permissions($user), 'membership_id' => $context->membership()->public_id,
            'location_scope' => $context->membership()->location_scope->value]]);
    }
}
