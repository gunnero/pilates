<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read StudioMember $member
 * @property-read MembershipPlan $plan
 */
class MemberMembership extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['agreed_price_minor' => 'integer', 'duration_count' => 'integer', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<StudioMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(StudioMember::class, 'studio_member_id');
    }

    /** @return BelongsTo<MembershipPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }
}
