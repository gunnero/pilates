<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\OrganizationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** @property OrganizationStatus $status */
class Organization extends Model
{
    use HasPublicId;

    protected $fillable = ['name'];

    protected $hidden = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => OrganizationStatus::class];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization): void {
            $organization->slug ??= rtrim(substr(Str::slug($organization->name) ?: 'studio', 0, 55), '-').'-'.strtolower(Str::random(10));
            $organization->status ??= OrganizationStatus::Active;
            $organization->locale ??= config('app.locale');
            $organization->timezone ??= config('studio.timezone');
            $organization->currency ??= config('studio.currency');
        });
    }
}
