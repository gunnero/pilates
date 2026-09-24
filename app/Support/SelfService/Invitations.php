<?php

namespace App\Support\SelfService;

use App\Contracts\Accounts\InvitationDelivery;
use App\Models\MemberInvitation;
use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\Audit;
use App\Support\Lifecycle;
use App\Support\MemberDirectory;
use App\Support\MemberStatus;
use App\Support\OrganizationStatus;
use App\Support\Permission;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class Invitations
{
    /** @return array{id:string,status:string,delivery:string} */
    public static function issue(TenantContext $c, User $u, string $member, string $email): array
    {
        $token = Str::random(64);
        $i = Lifecycle::run($c, $u, Permission::MembersUpdate, function () use ($c, $u, $member, $email, $token) {
            Gate::forUser($u)->authorize(Permission::MembersStatusManage->value);
            MemberDirectory::authorize($c, $u);
            $m = StudioMember::where('organization_id', $c->organization()->id)->where('public_id', $member)->firstOrFail();
            abort_unless($m->status === MemberStatus::Active && $m->user_id === null, 409);
            foreach (MemberInvitation::where('studio_member_id', $m->id)->where('status', 'pending')->get() as $old) {
                $old->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
                Audit::record($c->organization(), $u, 'member.invitation_revoked', 'member_invitation', $old->public_id, []);
            }
            $i = new MemberInvitation;
            $i->forceFill(['organization_id' => $m->organization_id, 'studio_member_id' => $m->id, 'created_by' => $u->id, 'email' => $email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()])->save();
            Audit::record($c->organization(), $u, 'member.invited', 'member_invitation', $i->public_id, ['member_id' => $m->public_id]);

            return $i;
        });
        $delivery = 'accepted';
        try {
            app(InvitationDelivery::class)->send($email, rtrim(config('app.url'), '/').'/activate#'.http_build_query(['token' => $token, 'email' => $email]), $c->organization()->locale);
        } catch (\Throwable) {
            $delivery = 'failed';
        }

        return ['id' => $i->public_id, 'status' => 'pending', 'delivery' => $delivery];
    }

    public static function revoke(TenantContext $c, User $u, string $id): void
    {
        Lifecycle::run($c, $u, Permission::MembersUpdate, function () use ($c, $u, $id) {
            Gate::forUser($u)->authorize(Permission::MembersStatusManage->value);
            MemberDirectory::authorize($c, $u);
            $i = MemberInvitation::where('organization_id', $c->organization()->id)->where('public_id', $id)->firstOrFail();
            if ($i->status === 'pending') {
                $i->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
                Audit::record($c->organization(), $u, 'member.invitation_revoked', 'member_invitation', $i->public_id, []);
            }
        });
    }

    /** Existing accounts require their password; invitation possession never replaces account authentication.
     * @param  array<string,string>  $data
     */
    public static function activate(array $data): void
    {
        AccountSecurity::run($data['email'], function () use ($data) {
            $hint = MemberInvitation::where('token_hash', hash('sha256', $data['token']))->where('email', $data['email'])->first();
            if ($hint === null) {
                self::invalid();
            }
            $o = Organization::whereKey($hint->organization_id)->lockForUpdate()->firstOrFail();
            $i = MemberInvitation::whereKey($hint->id)->lockForUpdate()->firstOrFail();
            $m = StudioMember::where('organization_id', $o->id)->whereKey($i->studio_member_id)->lockForUpdate()->firstOrFail();
            if ($o->status !== OrganizationStatus::Active || $m->status !== MemberStatus::Active || $i->status !== 'pending' || ! $i->expires_at->isFuture() || $m->user_id !== null) {
                self::invalid();
            }
            $u = User::where('email', $i->email)->lockForUpdate()->first();
            if ($u !== null) {
                if (! Hash::check($data['password'], $u->password)) {
                    self::invalid();
                }if (StudioMember::where('organization_id', $o->id)->where('user_id', $u->id)->exists()) {
                    self::invalid();
                }
            } else {
                Validator::make($data, ['password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()]])->validate();
                $u = User::create(['name' => $m->first_name.' '.$m->last_name, 'email' => $i->email, 'password' => $data['password']]);
                $u->forceFill(['email_verified_at' => now()])->save();
            }
            $m->forceFill(['user_id' => $u->id, 'digital_access_mode' => 'app'])->save();
            $i->forceFill(['status' => 'accepted', 'accepted_user_id' => $u->id, 'consumed_at' => now()])->save();
            Audit::record($o, $u, 'member.account_linked', 'studio_member', $m->public_id, ['invitation_id' => $i->public_id, 'account_id' => $u->public_id]);
        });
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['activation' => __('selfservice.activation_invalid')]);
    }
}
