<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @property int $organization_id
 * @property CarbonImmutable $created_at
 * @property string $public_id
 * @property int|null $studio_member_id
 * @property int|null $organization_membership_id
 * @property string $recipient_key
 * @property string $type
 * @property string $event_key
 * @property string|null $source_fingerprint
 * @property int|null $training_session_id
 * @property int|null $reservation_id
 * @property int|null $member_membership_id
 * @property int|null $cash_payment_id
 * @property int|null $payment_reversal_id
 * @property int|null $attendance_record_id
 * @property CarbonImmutable|null $session_starts_at
 * @property string $title_mk
 * @property string $title_en
 * @property string $message_mk
 * @property string $message_en
 * @property array<string,string> $metadata
 * @property-read WaitingListEntry|null $waitingEntry
 * @property-read StudioAnnouncement|null $announcement
 * @property-read Organization $organization
 * @property-read StudioMember|null $member
 * @property-read OrganizationMembership|null $staff
 * @property-read TrainingSession|null $session
 * @property-read Reservation|null $reservation
 * @property-read MemberMembership|null $membership
 * @property-read CashPayment|null $cash
 * @property-read AttendanceRecord|null $attendance
 * @property-read NotificationDelivery|null $delivery
 */
class PlatformNotification extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'immutable_datetime', 'session_starts_at' => 'immutable_datetime'];
    }

    /** @return HasOne<NotificationDelivery,$this> */
    public function delivery(): HasOne
    {
        return $this->hasOne(NotificationDelivery::class)->where('channel', 'in_app');
    }

    /** @return BelongsTo<WaitingListEntry,$this> */
    public function waitingEntry(): BelongsTo
    {
        return $this->belongsTo(WaitingListEntry::class, 'waiting_list_entry_id');
    }

    /** @return BelongsTo<StudioAnnouncement,$this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(StudioAnnouncement::class, 'announcement_id');
    }

    /** @return BelongsTo<Organization,$this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id');
    }

    /** @return BelongsTo<StudioMember,$this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(StudioMember::class, 'studio_member_id');
    }

    /** @return BelongsTo<OrganizationMembership,$this> */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'organization_membership_id');
    }

    /** @return BelongsTo<TrainingSession,$this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /** @return BelongsTo<Reservation,$this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    /** @return BelongsTo<MemberMembership,$this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MemberMembership::class, 'member_membership_id');
    }

    /** @return BelongsTo<CashPayment,$this> */
    public function cash(): BelongsTo
    {
        return $this->belongsTo(CashPayment::class, 'cash_payment_id');
    }

    /** @return BelongsTo<AttendanceRecord,$this> */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }
}
