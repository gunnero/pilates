<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\DigitalAccessMode;
use App\Support\MemberStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Customer identity, never staff authority or a purchased package.
 * @property MemberStatus $status
 * @property DigitalAccessMode $digital_access_mode
 * @property-read Location|null $preferredLocation
 */
class StudioMember extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'user_id', 'notes'];

    protected function casts(): array
    {
        return ['status' => MemberStatus::class, 'digital_access_mode' => DigitalAccessMode::class];
    }

    /** @return BelongsTo<Location, $this> */
    public function preferredLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'preferred_location_id');
    }
}
