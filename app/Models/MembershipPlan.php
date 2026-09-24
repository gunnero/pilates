<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class MembershipPlan extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'duration_count' => 'integer'];
    }
}
