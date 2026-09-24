<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property int $organization_id
 * @property string $public_id
 * @property int|null $group_id
 * @property int $cursor_id
 * @property int $upper_member_id
 * @property string $state
 * @property CarbonImmutable $expires_at
 */
final class StudioAnnouncement extends Model
{
    use HasPublicId;

    protected $guarded = ['*'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
