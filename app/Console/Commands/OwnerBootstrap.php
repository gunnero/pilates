<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Audit;
use App\Support\StaffRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OwnerBootstrap extends Command
{
    protected $signature = 'gt:owner-bootstrap {organization} {user} {--reason=}';

    protected $description = 'Trusted migration recovery only: establish an owner when none exists';

    public function handle(): int
    {
        $data = Validator::make(['id' => $this->argument('organization'), 'user' => $this->argument('user'), 'reason' => $this->option('reason')], ['id' => 'required|uuid', 'user' => 'required|uuid', 'reason' => 'required|string|max:300'])->validate();
        DB::transaction(function () use ($data): void {
            $organization = Organization::query()->where('public_id', $data['id'])->lockForUpdate()->firstOrFail();
            if (OrganizationMembership::query()->where('organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['role' => __('lifecycle.owner_exists')]);
            }
            $user = User::query()->where('public_id', $data['user'])->firstOrFail();
            $membership = OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->first();
            if ($membership !== null && $membership->status->value === 'revoked') {
                throw ValidationException::withMessages(['status' => __('lifecycle.revoked_terminal')]);
            }
            $membership ??= new OrganizationMembership;
            $before = $membership->exists ? ['role' => $membership->role->value, 'permissions' => $membership->permissions, 'status' => $membership->status->value, 'location_scope' => $membership->location_scope->value, 'location_ids' => Location::query()->where('organization_id', $organization->id)->whereIn('id', DB::table('membership_locations')->select('location_id')->where('organization_membership_id', $membership->id))->pluck('public_id')->all()] : null;
            $membership->forceFill(['organization_id' => $organization->id, 'user_id' => $user->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
            DB::table('membership_locations')->where('organization_membership_id', $membership->id)->delete();
            Audit::record($organization, null, 'membership.owner_bootstrapped', 'organization_membership', $membership->public_id,
                ['before' => $before, 'after' => ['role' => 'owner', 'permissions' => $membership->permissions, 'status' => 'active', 'location_scope' => 'all'], 'reason' => $data['reason'], 'source' => 'operator']);
        });
        $this->info('Owner established.');

        return self::SUCCESS;
    }
}
