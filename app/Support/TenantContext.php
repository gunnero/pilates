<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use LogicException;

final class TenantContext
{
    private ?Organization $organization = null;

    private ?int $userId = null;

    /** @var list<string> */
    private array $permissions = [];

    public function resolve(User $user, string $publicId): void
    {
        $this->clear();
        // Membership and organization are selected together; another tenant is indistinguishable from absence.
        $organization = $user->organizations()->where('organizations.public_id', $publicId)->firstOrFail();
        $this->organization = $organization;
        $this->userId = $user->id;
        $this->permissions = $organization->pivot->permissions;
    }

    public function organization(): Organization
    {
        return $this->organization ?? throw new LogicException('Tenant context is required.');
    }

    public function permits(User $user, Permission $permission): bool
    {
        return $this->organization !== null && $this->userId === $user->id && in_array($permission->value, $this->permissions, true);
    }

    public function clear(): void
    {
        $this->organization = null;
        $this->userId = null;
        $this->permissions = [];
    }
}
