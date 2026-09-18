<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Support\Audit;
use App\Support\OrganizationStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrganizationStatusCommand extends Command
{
    protected $signature = 'gt:organization-status {organization} {status} {--reason=}';

    protected $description = 'Trusted operator: suspend or restore organization access with audit evidence';

    public function handle(): int
    {
        $data = Validator::make(['id' => $this->argument('organization'), 'status' => $this->argument('status'), 'reason' => $this->option('reason')], ['id' => ['required', 'uuid'], 'status' => ['required', Rule::enum(OrganizationStatus::class)], 'reason' => ['required', 'string', 'max:300']])->validate();
        DB::transaction(function () use ($data): void {
            $organization = Organization::query()->where('public_id', $data['id'])->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'active' && ! OrganizationMembership::query()->where('organization_id', $organization->id)->where('role', 'owner')->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['status' => __('lifecycle.last_owner')]);
            }
            if ($organization->status->value !== $data['status']) {
                $before = $organization->status->value;
                $organization->status = OrganizationStatus::from($data['status']);
                $organization->save();
                Audit::record($organization, null, 'organization.status_changed', 'organization', $organization->public_id, ['before' => $before, 'after' => $data['status'], 'reason' => $data['reason'], 'source' => 'operator']);
            }
        });
        $this->info('Organization lifecycle updated.');

        return self::SUCCESS;
    }
}
