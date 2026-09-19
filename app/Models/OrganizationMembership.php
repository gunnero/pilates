<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\LocationScope;
use App\Support\MembershipStatus;
use App\Support\StaffRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Staff access only; never a studio customer membership.
 * @property-read TrainerProfile|null $trainerProfile
 * @property StaffRole $role
 * @property MembershipStatus $status
 * @property LocationScope $location_scope
 * @property-read Organization $organization
 * @property-read User $user
 * @property list<string> $permissions
 */
class OrganizationMembership extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permissions' => 'array', 'status' => MembershipStatus::class, 'role' => StaffRole::class, 'location_scope' => LocationScope::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasOne<TrainerProfile, $this> */
    public function trainerProfile(): HasOne
    {
        return $this->hasOne(TrainerProfile::class, 'organization_membership_id');
    }
}
