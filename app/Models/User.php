<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasPublicId, Notifiable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    public function currentAccessToken(): ?HasAbilities
    {
        return $this->accessToken;
    }

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['id', 'password', 'remember_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email_verified_at' => 'immutable_datetime', 'password' => 'hashed'];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value): string => mb_strtolower(trim($value)));
    }

    /** @return HasMany<OrganizationMembership, $this> */
    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }
}
