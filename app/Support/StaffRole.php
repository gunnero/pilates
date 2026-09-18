<?php

namespace App\Support;

enum StaffRole: string
{
    case Owner = 'owner';
    case Administrator = 'administrator';
    case Trainer = 'trainer';
    case Staff = 'staff';

    /** @return list<string> */
    public static function memberPermissions(): array
    {
        return ['members.view', 'members.create', 'members.update', 'members.status.manage', 'members.notes.view', 'members.notes.manage'];
    }

    /** @return list<string> */
    public static function ownerMinimum(): array
    {
        return ['studio.access', 'organizations.view', 'organizations.manage', 'staff.view', 'staff.manage', 'permissions.view', 'permissions.manage'];
    }

    /**
     * Explicit bundles; adding a new enum permission never silently grants it.
     *
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => [...self::memberPermissions(), ...self::ownerMinimum(), 'locations.view', 'locations.manage', 'attendance.view', 'payments.view', 'payments.record', 'payments.correct', 'reports.view'],
            self::Administrator => [...self::memberPermissions(), 'studio.access', 'organizations.view', 'organizations.manage', 'locations.view', 'locations.manage', 'staff.view', 'staff.manage', 'permissions.view', 'attendance.view'],
            self::Trainer, self::Staff => ['studio.access', 'organizations.view', 'locations.view', 'attendance.view'],
        };
    }
}
