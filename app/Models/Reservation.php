<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read TrainingSession $session
 * @property-read StudioMember $member
 * @property-read PermanentSpot|null $permanentSpot */
class Reservation extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'created_by'];

    protected function casts(): array
    {
        return ['cancelled_at' => 'immutable_datetime'];
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

    /** @return BelongsTo<PermanentSpot, $this> */
    public function permanentSpot(): BelongsTo
    {
        return $this->belongsTo(PermanentSpot::class, 'permanent_spot_id');
    }
}
