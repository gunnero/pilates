<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read StudioMember $member
 * @property-read MemberMembership $membership
 * @property-read User $actor
 * @property CarbonImmutable $paid_at
 * @property-read PaymentReversal|null $reversal
 */
class CashPayment extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'paid_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    public const UPDATED_AT = null;

    /** @return BelongsTo<StudioMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(StudioMember::class, 'studio_member_id');
    }

    /** @return BelongsTo<MemberMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(MemberMembership::class, 'member_membership_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** @return HasOne<PaymentReversal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(PaymentReversal::class);
    }
}
