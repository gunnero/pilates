<?php

namespace App\Http\Controllers;

use App\Http\Requests\MemberTokenRequest;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\SelfService\AccountSecurity;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Explicit staff credentials, independent of member access and member tokens. */
final class OperationsAccountController
{
    private const ABILITIES = ['studio.access', 'sessions.view', 'attendance.view', 'attendance.manage', 'attendance.correct', 'reservations.view', 'reservations.manage', 'waiting_list.view', 'members.view', 'memberships.view', 'payments.view', 'organizations.manage', 'account.security'];

    public function token(MemberTokenRequest $r): JsonResponse
    {
        $data = AccountSecurity::run($r->validated('email'), function () use ($r) {
            $u = User::where('email', $r->validated('email'))->first();
            if ($u === null || ! Hash::check($r->validated('password'), $u->password) || ! $this->memberships($u)->exists()) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            $u->tokens()->where('expires_at', '<=', now())->delete();
            abort_if($u->tokens()->count() >= 10, 429);
            $expires = now()->addDays(30);
            $token = $u->createToken($r->validated('device_name'), self::ABILITIES, $expires);
            AccountSecurity::audit($u, 'token_issued');

            return ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString()];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store, private');
    }

    public function contexts(Request $r): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = MemberAccountController::actor($r);
        MemberAccountController::ability($u, 'studio.access');
        $memberships = $this->memberships($u)->with('organization')->orderBy('id')->limit(101)->get();

        return response()->json(['data' => ['id' => $u->public_id, 'name' => $u->name, 'organizations' => $memberships->take(100)->map(fn ($m) => ['id' => $m->organization->public_id, 'name' => $m->organization->name]), 'truncated' => $memberships->count() > 100]])->header('Cache-Control', 'no-store, private');
    }

    /** @return HasMany<OrganizationMembership, User> */
    private function memberships(User $u): HasMany
    {
        return $u->organizationMemberships()->where('status', 'active')->whereJsonContains('permissions', 'studio.access')->whereJsonContains('permissions', 'sessions.view')->whereJsonContains('permissions', 'attendance.view')->whereHas('organization', fn ($q) => $q->where('status', 'active'));
    }
}
