<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\LocationStatus;
use Illuminate\Database\Eloquent\Model;

/** @property LocationStatus $status */
class Location extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => LocationStatus::class];
    }
}
