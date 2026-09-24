<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\StudioSettings;
use Illuminate\Database\Eloquent\Model;

final class StudioSetting extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id'];

    protected function casts(): array
    {
        return array_map(fn ($definition) => $definition['type'], StudioSettings::catalog());
    }
}
