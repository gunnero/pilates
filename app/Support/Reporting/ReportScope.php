<?php

namespace App\Support\Reporting;

use App\Models\User;
use App\Support\LocationScope;
use App\Support\Permission;
use App\Support\StaffRole;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Gate;

final class ReportScope
{
    public static function authorize(TenantContext $c, User $u, string $report, bool $details = false): void
    {
        Gate::forUser($u)->authorize(Permission::ReportsView->value);
        if (in_array($report, ['revenue', 'settlement'], true)) {
            abort_if($c->membership()->role === StaffRole::Trainer, 403);
            Gate::forUser($u)->authorize(Permission::PaymentsView->value);
        } elseif (in_array($report, ['activity', 'expiry'], true)) {
            abort_unless($c->membership()->location_scope === LocationScope::All && $c->membership()->role !== StaffRole::Trainer, 403);
            Gate::forUser($u)->authorize(Permission::MembersView->value);
            Gate::forUser($u)->authorize(Permission::MembershipsView->value);
        } else {
            Gate::forUser($u)->authorize(Permission::SessionsView->value);
            Gate::forUser($u)->authorize(Permission::AttendanceView->value);
        }
    }

    /** Fingerprint only authority that can change report visibility; used across snapshot/audit boundaries. */
    public static function fingerprint(TenantContext $c, User $u): string
    {
        $locations = $c->locations()->orderBy('id')->pluck('locations.id')->all();

        return hash('sha256', json_encode([$c->organization()->timezone, $c->membership()->role, $c->membership()->location_scope, $c->permissions($u), $locations], JSON_THROW_ON_ERROR));
    }
}
