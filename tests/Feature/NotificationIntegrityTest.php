<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformNotification;
use App\Support\Notifications\Delivery;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class NotificationIntegrityTest extends TestCase
{
    use DatabaseMigrations,NotificationFixtures;

    private function rejected(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database accepted invalid evidence');
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), ['23514', '23503', '23505', '22001', '22023']);
        }
    }

    public function test_database_enforces_tenant_sources_payload_immutability_and_delivery_transitions(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $n = PlatformNotification::firstOrFail();
        $foreign = Organization::create(['name' => 'Foreign']);
        $fm = $this->notificationMember($foreign);
        $raw = (array) DB::table('platform_notifications')->first();
        unset($raw['id'],$raw['recipient_key']);
        foreach ([
            ['studio_member_id' => $fm->id], ['type' => 'arbitrary'], ['reservation_id' => null], ['training_session_id' => null],
            ['organization_membership_id' => $a->id], ['metadata' => '{"secret":"x"}'], ['metadata' => '[]'],
            ['metadata' => json_encode(['date' => str_repeat('x', 101)])], ['title_en' => str_repeat('x', 161)],
        ] as $patch) {
            $this->rejected(fn () => DB::table('platform_notifications')->insert(array_replace($raw, ['public_id' => (string) Str::uuid7(), 'event_key' => (string) Str::uuid7()], $patch)));
        }
        $this->rejected(fn () => DB::table('platform_notifications')->insert(array_replace($raw, ['public_id' => (string) Str::uuid7()])));
        $this->rejected(fn () => DB::table('platform_notifications')->where('id', $n->id)->update(['title_en' => 'Tampered']));
        $this->rejected(fn () => DB::table('platform_notifications')->where('id', $n->id)->delete());
        $d = $n->delivery;
        $this->rejected(fn () => DB::table('notification_deliveries')->where('id', $d->id)->update(['organization_id' => $foreign->id]));
        $this->rejected(fn () => DB::table('notification_deliveries')->where('id', $d->id)->update(['state' => 'delivered', 'delivered_at' => now()]));
        $this->rejected(fn () => DB::table('notification_delivery_attempts')->insert(['organization_id' => $o->id, 'notification_delivery_id' => $d->id, 'attempt' => 1, 'lease_token' => (string) Str::uuid7(), 'outcome' => 'started', 'started_at' => now()]));
        $this->assertSame('delivered', Delivery::run($d->id));
        $this->rejected(fn () => DB::table('notification_deliveries')->where('id', $d->id)->update(['state' => 'pending', 'delivered_at' => null]));
        $this->rejected(fn () => DB::table('notification_delivery_attempts')->update(['outcome' => 'failed']));
        $this->actingAs($recipient)->postJson($this->base($o)."/notifications/$n->public_id/read")->assertOk();
        $this->rejected(fn () => DB::table('notification_reads')->update(['read_at' => now()->addHour()]));
        $this->rejected(fn () => DB::table('audit_events')->update(['action' => 'tampered']));
    }

    public function test_expired_lease_recovers_and_stale_worker_cannot_publish_twice(): void
    {
        $this->notificationFixture();
        $d = NotificationDelivery::firstOrFail();
        $lease = (string) Str::uuid7();
        DB::transaction(function () use ($d, $lease) {
            $d->forceFill(['state' => 'processing', 'attempts' => 1, 'lease_token' => $lease, 'lease_expires_at' => now()->subSecond()])->save();
            DB::table('notification_delivery_attempts')->insert(['organization_id' => $d->organization_id, 'notification_delivery_id' => $d->id, 'attempt' => 1, 'lease_token' => $lease, 'outcome' => 'started', 'started_at' => now()->subMinutes(3)]);
        });
        $this->assertSame('delivered', Delivery::run($d->id));
        $this->assertDatabaseHas('notification_delivery_attempts', ['attempt' => 1, 'outcome' => 'abandoned']);
        $this->assertDatabaseHas('notification_delivery_attempts', ['attempt' => 2, 'outcome' => 'delivered']);
        $this->assertSame('skipped', Delivery::run($d->id));
    }
}
