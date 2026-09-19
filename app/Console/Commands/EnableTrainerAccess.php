<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EnableTrainerAccess extends Command
{
    protected $signature = 'gt:trainer-access-enable {organization} {membership} {--reason=}';

    protected $description = 'Trusted GT-004 adoption: explicitly grant trainer management to an active all-location owner/administrator';

    public function handle(): int
    {
        $data = Validator::make(['organization' => $this->argument('organization'), 'membership' => $this->argument('membership'), 'reason' => $this->option('reason')], ['organization' => 'required|uuid', 'membership' => 'required|uuid', 'reason' => 'required|string|max:300'])->validate();
        DB::transaction(function () use ($data): void {
            $org = Organization::query()->where('public_id', $data['organization'])->lockForUpdate()->firstOrFail();
            $membership = OrganizationMembership::query()->where('organization_id', $org->id)->where('public_id', $data['membership'])->where('status', 'active')->where('location_scope', 'all')->whereIn('role', ['owner', 'administrator'])->firstOrFail();
            $before = $membership->permissions;
            $after = array_values(array_unique([...$before, 'trainers.view', 'trainers.manage']));
            if ($before !== $after) {
                $membership->permissions = $after;
                $membership->save();
                Audit::record($org, null, 'staff.permissions_changed', 'organization_membership', $membership->public_id, ['before' => $before, 'after' => $after, 'source' => 'operator', 'reason' => $data['reason']]);
            }
        });
        $this->info('Trainer management enabled; no trainer profile was created.');

        return self::SUCCESS;
    }
}
