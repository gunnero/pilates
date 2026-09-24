<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property int $organization_id
 * @property int $studio_member_id
 * @property int $user_id
 * @property int|null $access_token_id
 * @property string $public_id
 * @property string $token_ciphertext
 * @property string $token_hash
 * @property CarbonImmutable|null $revoked_at
 */
final class DevicePushToken extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'token_hash', 'token_ciphertext', 'user_id', 'access_token_id', 'studio_member_id', 'organization_id'];

    protected function casts(): array
    {
        return ['token_ciphertext' => 'encrypted', 'revoked_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }
}
