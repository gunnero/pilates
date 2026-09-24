<?php

namespace App\Support\SelfService;

use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\MemberStatus;
use App\Support\OrganizationStatus;
use Illuminate\Support\Facades\DB;

final class MemberContext
{
    public function __construct(public readonly User $user, public readonly Organization $organization, public readonly StudioMember $member) {}

    public static function resolve(User $u, string $organization, bool $write = false): self
    {
        abort_unless($u->currentAccessToken() === null || $u->tokenCan($write ? 'member.write' : 'member.read'), 403);
        $o = Organization::where('public_id', $organization)->where('status', OrganizationStatus::Active)->firstOrFail();
        $m = StudioMember::where('organization_id', $o->id)->where('user_id', $u->id)->where('status', MemberStatus::Active)->firstOrFail();

        return new self($u, $o, $m);
    }

    /** @template T
     * @param  \Closure(self):T  $operation
     * @return T
     */
    public static function mutate(User $u, string $organization, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($u, $organization, $operation) {
            Organization::where('public_id', $organization)->lockForUpdate()->firstOrFail();

            return $operation(self::resolve($u, $organization, true));
        }, 3);
    }
}
