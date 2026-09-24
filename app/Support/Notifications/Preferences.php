<?php

namespace App\Support\Notifications;

use App\Models\PlatformNotification;
use Illuminate\Support\Facades\DB;

final class Preferences
{
    public const DEFAULTS = ['reminders_enabled' => true, 'expiry_reminders_enabled' => true, 'session_changes_enabled' => true, 'waiting_updates_enabled' => true, 'announcements_enabled' => true, 'push_enabled' => true, 'email_enabled' => false];

    /** @param array<string,int> $identity
     * @return array<string,bool>
     */
    public static function effective(array $identity): array
    {
        $row = DB::table('notification_preferences')->where($identity)->first();

        $result = self::DEFAULTS;
        foreach ($result as $key => $default) {
            $result[$key] = $row->$key ?? $default;
        }

        return $result;
    }

    public static function permits(PlatformNotification $n, string $channel): bool
    {
        $p = self::effective(['organization_id' => $n->organization_id] + ($n->studio_member_id !== null ? ['studio_member_id' => $n->studio_member_id] : ['organization_membership_id' => $n->organization_membership_id ?? 0]));
        if (! ($p[$channel.'_enabled'] ?? true)) {
            return false;
        }
        $key = match ($n->type) {
            'session.reminder' => 'reminders_enabled',
            'membership.expiring' => 'expiry_reminders_enabled',
            'session.changed', 'session.cancelled' => 'session_changes_enabled',
            'waiting.promoted', 'waiting.joined', 'waiting.expired', 'waiting.session_cancelled' => 'waiting_updates_enabled',
            'studio.announcement' => 'announcements_enabled',
            default => null,
        };

        return $key === null || $p[$key];
    }
}
