<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $expires_at */
class MemberInvitation extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'organization_id', 'studio_member_id', 'token_hash', 'email', 'created_by', 'accepted_user_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
