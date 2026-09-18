<?php

namespace App\Support;

use App\Models\Location;
use App\Models\StudioMember;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class MemberLifecycle
{
    /** @param array<string, mixed> $data */
    public static function save(TenantContext $context, User $actor, ?string $publicId, array $data): StudioMember
    {
        return Lifecycle::run($context, $actor, $publicId === null ? Permission::MembersCreate : Permission::MembersUpdate, function () use ($context, $actor, $publicId, $data) {
            MemberDirectory::authorize($context, $actor);
            if (array_key_exists('notes', $data)) {
                Gate::forUser($actor)->authorize(Permission::MembersNotesManage->value);
            }
            $member = $publicId === null ? new StudioMember : MemberDirectory::query($context, $actor, true)->where('public_id', $publicId)->firstOrFail();
            abort_if($member->exists && $member->status === MemberStatus::Archived, 409);
            $creating = ! $member->exists;
            if (array_key_exists('preferred_location_id', $data)) {
                Gate::forUser($actor)->authorize(Permission::LocationsView->value);
            }
            if (array_key_exists('preferred_location_id', $data) && $data['preferred_location_id'] !== null) {
                $location = $context->locations()->where('public_id', $data['preferred_location_id'])->firstOrFail();
                // Retain an existing historical preference; new assignments must be active.
                if ($location->status !== LocationStatus::Active && $member->preferred_location_id !== $location->id) {
                    throw ValidationException::withMessages(['preferred_location_id' => __('members.active_location')]);
                }
                $data['preferred_location_id'] = $location->id;
            }
            $before = $creating ? [] : self::evidence($member);
            if ($creating) {
                $member->forceFill(['organization_id' => $context->organization()->id, 'status' => MemberStatus::Active, 'preferred_locale' => $context->organization()->locale, 'digital_access_mode' => DigitalAccessMode::Offline]);
            }
            $member->forceFill($data);
            $fields = array_keys($member->getDirty());
            if ($creating || $fields !== []) {
                $member->save();
                $after = self::evidence($member);
                if ($creating) {
                    Audit::record($context->organization(), $actor, 'member.created', 'studio_member', $member->public_id, ['after' => $after]);
                } else {
                    $profileFields = array_values(array_intersect($fields, ['first_name', 'last_name', 'email', 'phone', 'preferred_locale']));
                    if ($profileFields !== []) {
                        Audit::record($context->organization(), $actor, 'member.profile_changed', 'studio_member', $member->public_id,
                            ['fields' => $profileFields, 'before' => array_intersect_key($before, array_flip(['has_email', 'has_phone', 'preferred_locale'])), 'after' => array_intersect_key($after, array_flip(['has_email', 'has_phone', 'preferred_locale']))]);
                    }
                    foreach (['digital_access_mode' => 'member.digital_access_changed', 'preferred_location_id' => 'member.preferred_location_changed'] as $key => $action) {
                        if (in_array($key, $fields, true)) {
                            Audit::record($context->organization(), $actor, $action, 'studio_member', $member->public_id, ['before' => $before[$key], 'after' => $after[$key]]);
                        }
                    }
                }
                if (in_array('notes', $fields, true)) {
                    Audit::record($context->organization(), $actor, 'member.notes_changed', 'studio_member', $member->public_id, ['changed' => true]);
                }
            }

            return MemberDirectory::query($context, $actor, true)->whereKey($member->id)->firstOrFail();
        });
    }

    public static function status(TenantContext $context, User $actor, string $publicId, MemberStatus $status): StudioMember
    {
        return Lifecycle::run($context, $actor, Permission::MembersStatusManage, function () use ($context, $actor, $publicId, $status) {
            $member = MemberDirectory::query($context, $actor, true)->where('public_id', $publicId)->firstOrFail();
            $before = $member->status;
            if ($before === MemberStatus::Archived && $status === MemberStatus::Active) {
                throw ValidationException::withMessages(['status' => __('members.restore_inactive')]);
            }
            if ($before !== $status) {
                $member->status = $status;
                $member->save();
                Audit::record($context->organization(), $actor, 'member.status_changed', 'studio_member', $member->public_id, ['before' => $before->value, 'after' => $status->value]);
            }

            return $member;
        });
    }

    /** Minimized snapshots: no names, contact values or note text.
     * @return array<string, mixed>
     */
    private static function evidence(StudioMember $member): array
    {
        // Resolve only the non-PII public location ID, including for callers without location view.
        $locationId = $member->preferred_location_id === null ? null : Location::query()->where('organization_id', $member->organization_id)->whereKey($member->preferred_location_id)->value('public_id');

        return ['status' => $member->status->value, 'digital_access_mode' => $member->digital_access_mode->value,
            'preferred_locale' => $member->preferred_locale, 'has_email' => $member->email !== null, 'has_phone' => $member->phone !== null, 'preferred_location_id' => $locationId];
    }
}
