<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LifecycleMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_preserves_legacy_ids_and_permissions_without_granting_ownership(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 7, '--force' => true]);
        $user = User::factory()->create();
        $publicId = (string) Str::uuid7();
        $id = DB::table('organizations')->insertGetId(['public_id' => $publicId, 'name' => 'Legacy Studio', 'locale' => 'mk', 'timezone' => 'Europe/Skopje', 'currency' => 'MKD', 'created_at' => now(), 'updated_at' => now()]);
        $access = DB::table('organization_user')->insertGetId(['organization_id' => $id, 'user_id' => $user->id, 'permissions' => '["studio.access","payments.record"]', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('migrate', ['--force' => true]);
        $this->assertDatabaseHas('organizations', ['id' => $id, 'public_id' => $publicId, 'name' => 'Legacy Studio', 'status' => 'active']);
        $this->assertDatabaseHas('organization_memberships', ['id' => $access, 'organization_id' => $id, 'user_id' => $user->id, 'role' => 'staff', 'status' => 'active']);
        $this->assertSame(['studio.access', 'payments.record'], json_decode(DB::table('organization_memberships')->where('id', $access)->value('permissions'), true));
        $this->assertTrue(Str::isUuid(DB::table('organization_memberships')->where('id', $access)->value('public_id')));
        $this->assertDatabaseCount('audit_events', 0);
        $this->artisan('gt:owner-bootstrap', ['organization' => $publicId, 'user' => $user->public_id, '--reason' => 'GT-001 adoption'])->assertSuccessful();
        $this->assertDatabaseHas('organization_memberships', ['id' => $access, 'role' => 'owner']);
        $this->assertDatabaseHas('audit_events', ['action' => 'membership.owner_bootstrapped', 'actor_id' => null]);
    }
}
