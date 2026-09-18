<?php

namespace App\Support;

use App\Models\StudioMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class MemberDirectory
{
    public static function authorize(TenantContext $context, User $actor): void
    {
        Gate::forUser($actor)->authorize(Permission::MembersView->value);
        // No real member-to-session/group location relationship exists yet.
        abort_unless($context->membership()->location_scope === LocationScope::All, 403);
    }

    /** @return Builder<StudioMember> */
    public static function query(TenantContext $context, User $actor, bool $detail = false): Builder
    {
        self::authorize($context, $actor);
        $columns = ['id', 'public_id', 'organization_id', 'first_name', 'last_name', 'phone', 'email', 'status', 'preferred_locale', 'digital_access_mode', 'preferred_location_id', 'created_at', 'updated_at'];
        if ($detail && Gate::forUser($actor)->allows(Permission::MembersNotesView->value)) {
            $columns[] = 'notes';
        }
        $query = StudioMember::query()->where('organization_id', $context->organization()->id)->select($columns);
        if (Gate::forUser($actor)->allows(Permission::LocationsView->value)) {
            $query->with('preferredLocation:id,public_id,name,status');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public static function represent(StudioMember $member, User $actor, bool $detail = false): array
    {
        $data = ['id' => $member->public_id, 'first_name' => $member->first_name, 'last_name' => $member->last_name,
            'phone' => $member->phone, 'email' => $member->email, 'status' => $member->status->value,
            'preferred_locale' => $member->preferred_locale, 'digital_access_mode' => $member->digital_access_mode->value,
            'created_at' => $member->created_at?->toISOString(), 'updated_at' => $member->updated_at?->toISOString()];
        if (Gate::forUser($actor)->allows(Permission::LocationsView->value)) {
            $location = $member->preferredLocation;
            $data['preferred_location'] = $location === null ? null : ['id' => $location->public_id, 'name' => $location->name, 'status' => $location->status->value];
        }
        if ($detail && Gate::forUser($actor)->allows(Permission::MembersNotesView->value)) {
            $data['notes'] = $member->notes;
        }

        return $data;
    }

    public static function duplicateWarning(StudioMember $member): bool
    {
        // A warning only: shared family contacts and names are legitimate, including archived profiles.
        return StudioMember::query()->where('organization_id', $member->organization_id)->whereKeyNot($member->id)
            ->where(function (Builder $query) use ($member): void {
                $query->where(fn (Builder $name) => $name->whereRaw('lower(first_name) = ?', [mb_strtolower($member->first_name)])->whereRaw('lower(last_name) = ?', [mb_strtolower($member->last_name)]));
                if ($member->email !== null) {
                    $query->orWhere('email', $member->email);
                }
                if ($member->phone !== null) {
                    $query->orWhere('phone', $member->phone);
                }
            })->exists();
    }
}
