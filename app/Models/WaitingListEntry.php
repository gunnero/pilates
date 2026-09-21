<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable $joined_at
 * @property-read TrainingSession $session
 * @property-read StudioMember $member */
class WaitingListEntry extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'created_by'];

    protected function casts(): array
    {
        return ['joined_at' => 'immutable_datetime', 'promoted_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<TrainingSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'training_session_id');
    }

    /** @return BelongsTo<StudioMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(StudioMember::class, 'studio_member_id');
    }
}
