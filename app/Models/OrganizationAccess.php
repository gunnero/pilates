<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Bootstrap access relation: query only through the authenticated user's organizations().
 *
 * @property list<string> $permissions
 */
class OrganizationAccess extends Pivot
{
    protected $table = 'organization_user';

    public $incrementing = true;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
