<?php

namespace App\Http\Controllers;

use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

class OrganizationContextController
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $organization = $context->organization();

        return response()->json(['data' => [
            'id' => $organization->public_id,
            'name' => $organization->name,
            'locale' => $organization->locale,
            'timezone' => $organization->timezone,
            'currency' => $organization->currency,
        ]]);
    }
}
