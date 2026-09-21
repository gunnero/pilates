<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read TrainingGroup $group
 * @property-read StudioMember $member */
class PermanentSpot extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'created_by'];

    protected function casts(): array
    {
        return [];
    }

    /** @return BelongsTo<TrainingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'training_group_id');
    }

    /** @return BelongsTo<StudioMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(StudioMember::class, 'studio_member_id');
    }
}
