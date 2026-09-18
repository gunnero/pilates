<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class Audit
{
    /** @param array<string, mixed> $metadata */
    public static function record(Organization $organization, ?User $actor, string $action, string $subjectType, string $subjectId, array $metadata): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Audit requires an enclosing transaction.');
        }
        DB::table('audit_events')->insert([
            'public_id' => (string) Str::uuid7(), 'organization_id' => $organization->id, 'actor_id' => $actor?->id,
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'occurred_at' => now(), 'metadata' => json_encode((object) $metadata, JSON_THROW_ON_ERROR),
        ]);
    }
}
