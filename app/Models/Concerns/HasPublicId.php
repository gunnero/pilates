<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            $model->public_id = (string) Str::uuid7();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
