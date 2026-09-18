<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => [
            'id' => $user->public_id,
            'name' => $user->name,
            'organizations' => $user->organizations()->get()->map(fn ($organization) => [
                'id' => $organization->public_id, 'name' => $organization->name,
            ]),
        ]]);
    }
}
