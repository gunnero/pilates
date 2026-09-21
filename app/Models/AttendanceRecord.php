<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read Reservation $reservation
 * @property CarbonImmutable|null $checked_in_at
 */
class AttendanceRecord extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'recorded_by'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
