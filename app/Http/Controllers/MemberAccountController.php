<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountPasswordRequest;
use App\Http\Requests\MemberTokenRequest;
use App\Http\Requests\ReservationListRequest;
use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\SelfService\AccountSecurity;
use App\Support\SelfService\MemberContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

final class MemberAccountController
{
    public static function actor(Request $r): User
    {
        $u = $r->user();
        abort_unless($u instanceof User, 401);

        return $u;
    }

    public static function ability(User $u, string $ability): void
    {
        abort_unless($u->currentAccessToken() === null || $u->tokenCan($ability), 403);
    }

    public function current(Request $r): JsonResponse
    {
        $u = self::actor($r);
        self::ability($u, 'member.read');

        return response()->json(['data' => ['id' => $u->public_id, 'name' => $u->name, 'email' => $u->email]]);
    }

    public function organizations(ReservationListRequest $r): JsonResponse
    {
        $u = self::actor($r);
        self::ability($u, 'member.read');
        $p = Organization::where('status', 'active')->whereIn('id', StudioMember::where('user_id', $u->id)->where('status', 'active')->select('organization_id'))->orderBy('id')->paginate($r->validated('per_page', 25));

        return response()->json(['data' => $p->getCollection()->map(fn ($o) => ['id' => $o->public_id, 'name' => $o->name, 'timezone' => $o->timezone, 'locale' => $o->locale, 'currency' => $o->currency]), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(), 'per_page' => $p->perPage()]]);
    }

    public function switch(Request $r, string $organization): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = self::actor($r);
        MemberContext::mutate($u, $organization, function (MemberContext $c) use ($r) {
            if ($r->hasSession()) {
                $r->session()->put('member_organization_id', $c->organization->public_id);
            }Audit::record($c->organization, $c->user, 'member.organization_selected', 'studio_member', $c->member->public_id, []);
        });

        return response()->json(['data' => ['organization_id' => $organization]]);
    }

    public function token(MemberTokenRequest $r): JsonResponse
    {
        $data = AccountSecurity::run($r->validated('email'), function () use ($r) {
            $u = User::where('email', $r->validated('email'))->first();
            if ($u === null || ! Hash::check($r->validated('password'), $u->password) || ! StudioMember::where('user_id', $u->id)->where('status', 'active')->whereIn('organization_id', Organization::where('status', 'active')->select('id'))->exists()) {
                throw ValidationException::withMessages(['email' => __('auth.failed')]);
            }
            $u->tokens()->where('expires_at', '<=', now())->delete();
            abort_if($u->tokens()->count() >= 10, 429);
            $expires = now()->addDays(30);
            $token = $u->createToken($r->validated('device_name'), ['member.read', 'member.write', 'account.security', 'notifications.view', 'notifications.read'], $expires);
            AccountSecurity::audit($u, 'token_issued');

            return ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString()];
        });

        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }

    public function logoutToken(Request $r): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = self::actor($r);
        self::ability($u, 'account.security');
        AccountSecurity::run($u->email, function () use ($u) {
            $t = $u->currentAccessToken();
            abort_unless($t instanceof PersonalAccessToken, 409);
            $u->tokens()->whereKey($t->id)->delete();
            AccountSecurity::audit($u, 'token_revoked');
        });

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function revokeTokens(Request $r): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        $u = self::actor($r);
        self::ability($u, 'account.security');
        AccountSecurity::run($u->email, function () use ($u) {
            $u->tokens()->delete();
            AccountSecurity::audit($u, 'tokens_revoked');
        });

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function password(AccountPasswordRequest $r): JsonResponse
    {
        $u = self::actor($r);
        self::ability($u, 'account.security');
        AccountSecurity::run($u->email, function () use ($u, $r) {
            $current = User::whereKey($u->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($r->validated('current_password'), $current->password)) {
                throw ValidationException::withMessages(['current_password' => __('auth.failed')]);
            }$current->forceFill(['password' => $r->validated('password'), 'remember_token' => Str::random(60)])->save();
            AccountSecurity::revoke($current);
            AccountSecurity::audit($current, 'password_changed');
        });
        if ($r->hasSession()) {
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }

        return response()->json(['message' => __('auth.reset_done')]);
    }
}
