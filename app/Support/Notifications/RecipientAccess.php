<?php

namespace App\Support\Notifications;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PlatformNotification;
use App\Models\StudioMember;
use App\Models\User;
use App\Support\Permission;
use App\Support\SessionDirectory;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** Notification-only recipient scope. Never grants staff or member self-service domain access. */
final class RecipientAccess
{
    public function __construct(public readonly Organization $organization, public readonly ?StudioMember $member, public readonly ?OrganizationMembership $staff, private readonly User $user) {}

    public static function ability(User $u, bool $write = false): void
    {
        abort_unless($u->currentAccessToken() === null || ($u->tokenCan('notifications.view') && (! $write || $u->tokenCan('notifications.read'))), 403);
    }

    public static function resolve(User $u, string $organization, bool $write = false): self
    {
        self::ability($u, $write);
        $org = Organization::query()->where('public_id', $organization)->where('status', 'active')->firstOrFail();
        $member = StudioMember::query()->where('organization_id', $org->id)->where('user_id', $u->id)->where('status', 'active')->first();
        $staff = OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $u->id)->where('status', 'active')->whereJsonContains('permissions', 'studio.access')->first();
        if ($staff !== null) {
            $c = app(TenantContext::class);
            $c->resolve($u, $organization);
            // Token notification abilities do not replace the existing staff access ability.
            if (! Gate::forUser($u)->allows(Permission::StudioAccess->value)) {
                $staff = null;
            }
        }
        abort_unless($member !== null || $staff !== null, 404);

        return new self($org, $member, $staff, $u);
    }

    /** @return Builder<PlatformNotification> */
    public function query(): Builder
    {
        return PlatformNotification::query()->where('organization_id', $this->organization->id)->where(function (Builder $q) {
            $q->whereRaw('false');
            if ($this->member !== null) {
                $q->orWhere('studio_member_id', $this->member->id);
            }
            if ($this->staff !== null && Gate::forUser($this->user)->allows(Permission::SessionsView->value)) {
                $sessions = SessionDirectory::query(app(TenantContext::class), $this->user)->select('training_sessions.id');
                $q->orWhere(fn (Builder $s) => $s->where('organization_membership_id', $this->staff->id)->whereIn('training_session_id', $sessions));
            }
        })->whereHas('delivery', fn (Builder $q) => $q->where('state', 'delivered'));
    }

    /** Preference identity is explicit: members when linked, otherwise staff. No supplied recipient ID.
     * @return array<string,int>
     */
    public function preferenceIdentity(): array
    {
        return ['organization_id' => $this->organization->id] + ($this->member !== null ? ['studio_member_id' => $this->member->id] : ['organization_membership_id' => $this->staff->id ?? throw new \LogicException('Missing recipient')]);
    }
}
