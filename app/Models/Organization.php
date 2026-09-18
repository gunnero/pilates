<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/** @property OrganizationAccess $pivot */
class Organization extends Model
{
    use HasPublicId;

    protected $fillable = ['name'];

    protected $hidden = ['id'];

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->locale ??= config('app.locale');
            $organization->timezone ??= config('studio.timezone');
            $organization->currency ??= config('studio.currency');
        });
    }
}
