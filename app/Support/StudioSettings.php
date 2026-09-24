<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\StudioSetting;
use App\Models\TrainingSession;

/** Immutable request-local policy. Writers resolve AFTER acquiring the organization lock. */
final readonly class StudioSettings
{
    public function __construct(
        public int $reservation_horizon_days,
        public int $reservation_cutoff_minutes,
        public int $cancellation_cutoff_minutes,
        public bool $waiting_list_enabled,
        public bool $self_reservation_enabled,
        public bool $self_cancellation_enabled,
        public bool $self_check_in_enabled,
        public int $check_in_early_minutes,
        public int $check_in_late_minutes,
        public int $qr_lifetime_seconds,
        public int $qr_rotation_seconds,
        public bool $session_reminders_enabled,
        public int $reminder_lead_hours,
        public bool $expiry_warnings_enabled,
        public int $expiry_warning_days,
        public int $membership_expiring_days,
    ) {}

    /** @return array<string,array{type:string,default:bool|int,min:?int,max:?int,category:string}> */
    public static function catalog(): array
    {
        return [
            'reservation_horizon_days' => ['type' => 'integer', 'default' => 0, 'min' => 0, 'max' => 56, 'category' => 'reservations'],
            'reservation_cutoff_minutes' => ['type' => 'integer', 'default' => 0, 'min' => 0, 'max' => 1440, 'category' => 'reservations'],
            'cancellation_cutoff_minutes' => ['type' => 'integer', 'default' => 0, 'min' => 0, 'max' => 1440, 'category' => 'reservations'],
            'waiting_list_enabled' => ['type' => 'boolean', 'default' => true, 'min' => null, 'max' => null, 'category' => 'reservations'],
            'self_reservation_enabled' => ['type' => 'boolean', 'default' => true, 'min' => null, 'max' => null, 'category' => 'member_app'],
            'self_cancellation_enabled' => ['type' => 'boolean', 'default' => true, 'min' => null, 'max' => null, 'category' => 'member_app'],
            'self_check_in_enabled' => ['type' => 'boolean', 'default' => true, 'min' => null, 'max' => null, 'category' => 'member_app'],
            'check_in_early_minutes' => ['type' => 'integer', 'default' => 30, 'min' => 0, 'max' => 120, 'category' => 'attendance'],
            'check_in_late_minutes' => ['type' => 'integer', 'default' => 30, 'min' => 0, 'max' => 120, 'category' => 'attendance'],
            'qr_lifetime_seconds' => ['type' => 'integer', 'default' => 90, 'min' => 30, 'max' => 90, 'category' => 'attendance'],
            'qr_rotation_seconds' => ['type' => 'integer', 'default' => 30, 'min' => 10, 'max' => 30, 'category' => 'attendance'],
            'session_reminders_enabled' => ['type' => 'boolean', 'default' => true, 'min' => null, 'max' => null, 'category' => 'notifications'],
            'reminder_lead_hours' => ['type' => 'integer', 'default' => 24, 'min' => 1, 'max' => 72, 'category' => 'notifications'],
            'expiry_warnings_enabled' => ['type' => 'boolean', 'default' => true, 'min' => null, 'max' => null, 'category' => 'notifications'],
            'expiry_warning_days' => ['type' => 'integer', 'default' => 7, 'min' => 1, 'max' => 30, 'category' => 'notifications'],
            'membership_expiring_days' => ['type' => 'integer', 'default' => 7, 'min' => 1, 'max' => 30, 'category' => 'notifications'],
        ];
    }

    public static function forOrganization(Organization|int $organization): self
    {
        return self::fromRow(StudioSetting::where('organization_id', $organization instanceof Organization ? $organization->id : $organization)->first());
    }

    public static function fromRow(?StudioSetting $row): self
    {
        return new self(
            reservation_horizon_days: (int) ($row?->getAttribute('reservation_horizon_days') ?? self::catalog()['reservation_horizon_days']['default']),
            reservation_cutoff_minutes: (int) ($row?->getAttribute('reservation_cutoff_minutes') ?? self::catalog()['reservation_cutoff_minutes']['default']),
            cancellation_cutoff_minutes: (int) ($row?->getAttribute('cancellation_cutoff_minutes') ?? self::catalog()['cancellation_cutoff_minutes']['default']),
            waiting_list_enabled: (bool) ($row?->getAttribute('waiting_list_enabled') ?? self::catalog()['waiting_list_enabled']['default']),
            self_reservation_enabled: (bool) ($row?->getAttribute('self_reservation_enabled') ?? self::catalog()['self_reservation_enabled']['default']),
            self_cancellation_enabled: (bool) ($row?->getAttribute('self_cancellation_enabled') ?? self::catalog()['self_cancellation_enabled']['default']),
            self_check_in_enabled: (bool) ($row?->getAttribute('self_check_in_enabled') ?? self::catalog()['self_check_in_enabled']['default']),
            check_in_early_minutes: (int) ($row?->getAttribute('check_in_early_minutes') ?? self::catalog()['check_in_early_minutes']['default']),
            check_in_late_minutes: (int) ($row?->getAttribute('check_in_late_minutes') ?? self::catalog()['check_in_late_minutes']['default']),
            qr_lifetime_seconds: (int) ($row?->getAttribute('qr_lifetime_seconds') ?? self::catalog()['qr_lifetime_seconds']['default']),
            qr_rotation_seconds: (int) ($row?->getAttribute('qr_rotation_seconds') ?? self::catalog()['qr_rotation_seconds']['default']),
            session_reminders_enabled: (bool) ($row?->getAttribute('session_reminders_enabled') ?? self::catalog()['session_reminders_enabled']['default']),
            reminder_lead_hours: (int) ($row?->getAttribute('reminder_lead_hours') ?? self::catalog()['reminder_lead_hours']['default']),
            expiry_warnings_enabled: (bool) ($row?->getAttribute('expiry_warnings_enabled') ?? self::catalog()['expiry_warnings_enabled']['default']),
            expiry_warning_days: (int) ($row?->getAttribute('expiry_warning_days') ?? self::catalog()['expiry_warning_days']['default']),
            membership_expiring_days: (int) ($row?->getAttribute('membership_expiring_days') ?? self::catalog()['membership_expiring_days']['default']),
        );
    }

    /** @return array<string,bool|int> */
    public function values(): array
    {
        return get_object_vars($this);
    }

    public function canReserve(TrainingSession $s): bool
    {
        return $this->self_reservation_enabled
            && now()->lt($s->starts_at->subMinutes($this->reservation_cutoff_minutes))
            && ($this->reservation_horizon_days === 0 || $s->starts_at->lte(now()->addDays($this->reservation_horizon_days)));
    }

    public function canCancel(TrainingSession $s): bool
    {
        return $this->self_cancellation_enabled && now()->lt($s->starts_at->subMinutes($this->cancellation_cutoff_minutes));
    }
}
