<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read TrainingGroup $group
 * @property-read Location $location
 * @property-read TrainerProfile $trainer
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class TrainingSession extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'training_group_id', 'schedule_rule_id', 'location_id', 'trainer_profile_id'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'is_overridden' => 'boolean', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<TrainingGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(TrainingGroup::class, 'training_group_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<TrainerProfile, $this> */
    public function trainer(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'trainer_profile_id');
    }
}
