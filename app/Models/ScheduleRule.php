<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class ScheduleRule extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'training_group_id', 'replaces_rule_id'];

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'start_minute' => 'integer', 'end_minute' => 'integer', 'canceled_at' => 'immutable_datetime'];
    }
}
