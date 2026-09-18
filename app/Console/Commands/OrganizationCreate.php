<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Audit;
use App\Support\StaffRole;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrganizationCreate extends Command
{
    protected $signature = 'gt:organization-create {name} {owner-email}';

    protected $description = 'Trusted operator: create an organization for an existing user; never creates credentials';

    public function handle(): int
    {
        $data = Validator::make(['name' => $this->argument('name'), 'email' => $this->argument('owner-email')], ['name' => 'required|string|max:160', 'email' => 'required|string|email|max:254'])->validate();
        $owner = User::query()->where('email', mb_strtolower(trim($data['email'])))->firstOrFail();
        $organization = DB::transaction(function () use ($data, $owner) {
            $organization = Organization::create(['name' => $data['name']]);
            $membership = new OrganizationMembership;
            $membership->forceFill(['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => StaffRole::Owner, 'status' => 'active', 'location_scope' => 'all', 'permissions' => StaffRole::Owner->permissions()])->save();
            Audit::record($organization, null, 'organization.created', 'organization', $organization->public_id, ['source' => 'operator']);
            Audit::record($organization, null, 'membership.created', 'organization_membership', $membership->public_id, ['role' => 'owner', 'user_id' => $owner->public_id, 'source' => 'operator']);

            return $organization;
        });
        $this->info($organization->public_id);

        return self::SUCCESS;
    }
}
