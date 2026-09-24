<?php

namespace App\Http\Controllers;

use App\Http\Requests\DevicePushRequest;
use App\Models\DevicePushToken;
use App\Support\Audit;
use App\Support\SelfService\MemberContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

final class DevicePushController
{
    public function store(DevicePushRequest $r, string $organization): JsonResponse
    {
        $u = MemberAccountController::actor($r);
        $login = $u->currentAccessToken();
        abort_unless($login instanceof PersonalAccessToken, 403);
        $id = MemberContext::mutate($u, $organization, function (MemberContext $c) use ($r, $login) {
            // Global token identity also serializes registrations in different tenants.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['push-account:'.$c->user->id]);
            $hash = hash('sha256', $r->validated('token'));
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['push:'.$hash]);
            abort_unless(PersonalAccessToken::whereKey($login->id)->where('tokenable_id', $c->user->id)->where('expires_at', '>', now())->exists(), 401);
            DB::table('push_token_owners')->insertOrIgnore(['token_hash' => $hash, 'user_id' => $c->user->id]);
            abort_unless(DB::table('push_token_owners')->where('token_hash', $hash)->where('user_id', $c->user->id)->exists(), 409);
            $d = DevicePushToken::where('organization_id', $c->organization->id)->where('token_hash', $hash)->first();
            $active = DevicePushToken::where('user_id', $c->user->id)->whereNull('revoked_at')->whereIn('access_token_id', PersonalAccessToken::where('expires_at', '>', now())->select('id'));
            if ($d !== null) {
                $active->where('id', '<>', $d->id);
            }
            abort_if($active->count() >= 10, 429);
            if ($d !== null) {
                // A token can belong to multiple studios for the same account; its account ownership never changes.
                abort_unless($d->user_id === $c->user->id && $d->organization_id === $c->organization->id && $d->studio_member_id === $c->member->id && $d->getAttribute('installation_id') === $r->validated('installation_id'), 409);
                $d->forceFill(['access_token_id' => $login->id, 'last_seen_at' => now(), 'revoked_at' => null])->save();
            } else {
                $d = new DevicePushToken;
                $d->forceFill(['organization_id' => $c->organization->id, 'studio_member_id' => $c->member->id, 'user_id' => $c->user->id, 'access_token_id' => $login->id, 'token_hash' => $hash, 'token_ciphertext' => $r->validated('token'), 'platform' => $r->validated('platform'), 'installation_id' => $r->validated('installation_id'), 'last_seen_at' => now()])->save();
            }
            Audit::record($c->organization, $c->user, 'notification.device_registered', 'studio_member', $c->member->public_id, ['device_id' => $d->public_id]);

            return $d->public_id;
        });

        return response()->json(['data' => ['id' => $id]], 201);
    }

    public function destroy(Request $r, string $organization, string $device): JsonResponse
    {
        abort_unless($r->all() === [], 422);
        MemberContext::mutate(MemberAccountController::actor($r), $organization, function (MemberContext $c) use ($device) {
            $d = DevicePushToken::where('organization_id', $c->organization->id)->where('studio_member_id', $c->member->id)->where('user_id', $c->user->id)->where('public_id', $device)->firstOrFail();
            if ($d->revoked_at === null) {
                $d->forceFill(['revoked_at' => now()])->save();
                Audit::record($c->organization, $c->user, 'notification.device_revoked', 'studio_member', $c->member->public_id, ['device_id' => $d->public_id]);
            }
        });

        return response()->json(['data' => ['revoked' => true]]);
    }
}
