<?php

namespace App\Support\Notifications;

use App\Models\Organization;
use App\Models\StudioAnnouncement;
use App\Models\StudioMember;
use Illuminate\Support\Facades\DB;

final class Announcements
{
    /** A batch and its cursor commit atomically under the shared organization lock. */
    public static function run(int $id): int
    {
        return DB::transaction(function () use ($id) {
            $hint = StudioAnnouncement::findOrFail($id);
            $org = Organization::whereKey($hint->organization_id)->lockForUpdate()->firstOrFail();
            $a = StudioAnnouncement::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($a->state !== 'pending' || $a->expires_at->isPast() || $org->getRawOriginal('status') !== 'active') {
                return 0;
            }
            $q = StudioMember::where('organization_id', $org->id)->where('status', 'active')->where('id', '>', $a->cursor_id)->where('id', '<=', $a->upper_member_id);
            if ($a->group_id !== null) {
                $q->where(function ($q) use ($a, $org) {
                    $q->whereIn('id', DB::table('permanent_spots')->where('organization_id', $org->id)->where('training_group_id', $a->group_id)->where('status', 'active')->select('studio_member_id'))
                        ->orWhereIn('id', DB::table('reservations')->where('organization_id', $org->id)->where('status', 'active')->whereIn('training_session_id', DB::table('training_sessions')->where('organization_id', $org->id)->where('training_group_id', $a->group_id)->where('starts_at', '>', now())->select('id'))->select('studio_member_id'));
                });
            }
            $members = $q->orderBy('id')->limit(100)->get();
            foreach ($members as $member) {
                Events::announcement($a, $member);
            }
            $a->forceFill(['cursor_id' => $members->last()->id ?? $a->cursor_id, 'state' => $members->count() < 100 ? 'complete' : 'pending'])->save();

            return $members->count();
        }, 3);
    }
}
