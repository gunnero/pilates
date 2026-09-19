<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\TrainerStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Operational teaching profile; never authentication, grants, or customer identity.
 * @property TrainerStatus $status
 * @property-read OrganizationMembership $membership
 */
class TrainerProfile extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'organization_membership_id'];

    protected function casts(): array
    {
        return ['status' => TrainerStatus::class];
    }

    /** @return BelongsTo<OrganizationMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(OrganizationMembership::class, 'organization_membership_id');
    }
}
