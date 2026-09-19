<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read Location $location
 * @property-read TrainerProfile|null $primaryTrainer
 * @property bool $trainer_available
 */
class TrainingGroup extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'location_id', 'primary_trainer_id'];

    protected function casts(): array
    {
        return ['capacity' => 'integer', 'trainer_available' => 'boolean'];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<TrainerProfile, $this> */
    public function primaryTrainer(): BelongsTo
    {
        return $this->belongsTo(TrainerProfile::class, 'primary_trainer_id');
    }

    /** @return HasMany<ScheduleRule, $this> */
    public function scheduleRules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class);
    }
}
