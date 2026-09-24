<?php

namespace Tests\Feature;

use App\Contracts\Notifications\DeliveryChannel;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Events;
use App\Support\Notifications\Generate;
use App\Support\Notifications\NotificationType;
use App\Support\Notifications\Registry;
use App\Support\StaffRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use DatabaseMigrations,NotificationFixtures;

    private function publish(): void
    {
        foreach (NotificationDelivery::pluck('id') as $id) {
            Delivery::run($id);
        }
    }

    public function test_durable_confirmation_recipient_inbox_read_and_bounds(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $base = $this->base($o).'/notifications';
        $n = PlatformNotification::firstOrFail();
        $this->assertSame('reservation.confirmed', $n->type);
        $this->assertStringNotContainsString('notifications.', $n->title_mk);
        $this->actingAs($recipient)->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        $this->publish();
        $this->getJson('/api/v1/notification-contexts')->assertOk()->assertJsonPath('data.0.id', $o->public_id);
        $this->getJson($base)->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('data.0.read_at', null)->assertJsonMissingPath('data.0.studio_member_id');
        $this->getJson("$base/$n->public_id", ['Accept-Language' => 'en'])->assertOk()->assertJsonPath('data.title', $n->title_en);
        $audits = DB::table('audit_events')->count();
        $this->postJson("$base/$n->public_id/read")->assertOk()->assertJsonPath('data.marked_read', 1);
        $this->postJson("$base/read", ['ids' => [$n->public_id]])->assertOk()->assertJsonPath('data.marked_read', 0);
        $this->getJson("$base?unread=1")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->assertSame($audits, DB::table('audit_events')->count());
        foreach (['per_page=101', 'page=10001', 'recipient_id=1', 'organization_id=1'] as $q) {
            $this->getJson("$base?$q")->assertUnprocessable();
        }
        $this->postJson("$base/read", ['ids' => array_fill(0, 101, $n->public_id)])->assertUnprocessable();
        $this->getJson($this->base($o).'/members')->assertNotFound();
    }

    public function test_other_recipient_foreign_tenant_tokens_and_mass_assignment(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $this->publish();
        $base = $this->base($o).'/notifications';
        $n = PlatformNotification::firstOrFail();
        $other = User::factory()->create();
        $this->notificationMember($o, $other);
        $this->actingAs($other)->getJson($base)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("$base/$n->public_id")->assertNotFound();
        $this->postJson("$base/$n->public_id/read")->assertNotFound();
        $this->postJson("$base/read", ['ids' => [$n->public_id]])->assertNotFound();
        $foreign = Organization::create(['name' => 'Foreign']);
        $this->actingAs($recipient)->getJson($this->base($foreign).'/notifications')->assertNotFound();
        Sanctum::actingAs($recipient, []);
        $this->getJson($base)->assertForbidden();
        Sanctum::actingAs($recipient, ['notifications.view']);
        $this->getJson($base)->assertOk();
        $this->postJson("$base/$n->public_id/read")->assertForbidden();
        Sanctum::actingAs($recipient, ['notifications.view', 'notifications.read']);
        $this->postJson("$base/$n->public_id/read", ['recipient_id' => $other->public_id])->assertUnprocessable();
        $this->postJson("$base/$n->public_id/read")->assertOk();
    }

    public function test_reminder_window_idempotency_preference_and_stale_suppression(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $this->travelTo($r->session->starts_at->subHours(23));
        $this->assertSame(1, Generate::run($o->public_id)['created']);
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $n = PlatformNotification::where('type', 'session.reminder')->firstOrFail();
        $base = $this->base($o).'/notifications';
        $this->actingAs($recipient)->patchJson("$base/preferences", ['reminders_enabled' => false, 'expiry_reminders_enabled' => true])->assertOk();
        $this->assertSame('suppressed', Delivery::run($n->delivery->id));
        $this->assertDatabaseHas('notification_deliveries', ['id' => $n->delivery->id, 'error_code' => 'preference']);
        $this->assertDatabaseHas('audit_events', ['action' => 'notification.preferences_changed']);
        $this->actingAs($u)->postJson($this->base($o)."/reservations/$r->public_id/cancel")->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $confirmation = PlatformNotification::where('type', 'reservation.confirmed')->firstOrFail();
        $this->assertSame('suppressed', Delivery::run($confirmation->delivery->id));
        $cancellation = PlatformNotification::where('type', 'reservation.cancelled')->firstOrFail();
        $this->assertSame('delivered', Delivery::run($cancellation->delivery->id));
    }

    public function test_retries_are_bounded_observable_and_delivery_is_idempotent(): void
    {
        $this->notificationFixture();
        $n = PlatformNotification::firstOrFail();
        $channel = new class implements DeliveryChannel
        {
            public function deliver(PlatformNotification $n, string $key): void
            {
                throw new \RuntimeException('secret provider error');
            }
        };
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame($i === 5 ? 'failed' : 'retry', Delivery::run($n->delivery->id, $channel));
            $this->assertSame('skipped', Delivery::run($n->delivery->id, $channel));
            $this->travel(61 * (2 ** ($i - 1)))->seconds();
        }
        $this->assertDatabaseCount('notification_delivery_attempts', 5);
        $this->assertDatabaseHas('notification_deliveries', ['state' => 'failed', 'attempts' => 5]);
        // GT-015 keeps automated failures in durable attempts and safe operational logs.
        $this->assertDatabaseMissing('audit_events', ['action' => 'notification.delivery_failed']);
        $this->assertStringNotContainsString('secret provider error', json_encode(DB::table('audit_events')->get()));
    }

    public function test_transaction_rollback_and_duplicate_domain_event(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        DB::transaction(function () use ($o, $r) {
            Organization::whereKey($o->id)->lockForUpdate()->firstOrFail();
            Events::reservation($r, NotificationType::ReservationConfirmed);
        });
        $this->assertDatabaseCount('platform_notifications', 1);
        DB::beginTransaction();
        Events::reservation($r, NotificationType::SessionReminder);
        DB::rollBack();
        $this->assertDatabaseCount('platform_notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertSame('delivered', Delivery::run(NotificationDelivery::firstOrFail()->id));
        $this->assertSame('skipped', Delivery::run(NotificationDelivery::firstOrFail()->id));
        $this->assertDatabaseCount('notification_delivery_attempts', 1);
    }

    public function test_waiting_promotion_generates_one_notice(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture(1);
        $second = $this->notificationMember($o);
        $base = $this->base($o);
        $this->postJson("$base/sessions/{$r->session->public_id}/reservations", ['member_id' => $second->public_id, 'allow_waiting' => true])->assertOk();
        $this->postJson("$base/reservations/$r->public_id/cancel")->assertOk();
        $this->postJson("$base/reservations/$r->public_id/cancel")->assertOk();
        $this->assertSame(1, PlatformNotification::where('type', 'waiting.promoted')->count());
    }

    public function test_registry_rejects_arbitrary_and_oversized_payloads(): void
    {
        foreach ([['secret' => 'x'], ['date' => str_repeat('x', 101)], ['date' => ['nested' => 'x']]] as $metadata) {
            try {
                Registry::payload(NotificationType::SessionReminder, $metadata);
                $this->fail('Accepted invalid payload');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_material_change_staff_scope_and_cancellation_notifications(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $session = $r->session;
        $this->patchJson($this->base($o)."/sessions/$session->public_id", ['location_id' => $session->location->public_id, 'trainer_id' => $session->trainer->public_id, 'capacity' => 12, 'local_date' => $session->local_date, 'start_time' => '19:00', 'end_time' => '20:00'])->assertOk();
        $this->publish();
        $this->assertSame(1, PlatformNotification::where('type', 'session.changed')->count());
        $this->getJson($this->base($o).'/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'staff.session_changed');
        $a->permissions = array_values(array_diff($a->permissions, ['sessions.view']));
        $a->save();
        $this->getJson($this->base($o).'/notifications')->assertOk()->assertJsonCount(0, 'data');
        $a->permissions = StaffRole::Owner->permissions();
        $a->save();
        $this->postJson($this->base($o)."/sessions/$session->public_id/cancel", [])->assertOk();
        $this->publish();
        $this->actingAs($recipient)->getJson($this->base($o).'/notifications')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(0, Generate::run($o->public_id)['created']);
    }

    public function test_reminder_uses_utc_instant_across_dst_and_bounded_catchup(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $session = $r->session;
        // Materialized concrete instants disambiguate the repeated Skopje 02:30 hour.
        DB::table('training_sessions')->where('id', $session->id)->update(['starts_at' => '2026-10-25T01:30:00Z', 'ends_at' => '2026-10-25T02:30:00Z', 'local_date' => '2026-10-25']);
        $this->travelTo(CarbonImmutable::parse('2026-10-24T01:29:00Z'));
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->travelTo(CarbonImmutable::parse('2026-10-24T01:30:00Z'));
        $this->assertSame(1, Generate::run($o->public_id)['created']);
        $n = PlatformNotification::where('type', 'session.reminder')->firstOrFail();
        $this->assertSame('02:30', $n->metadata['time']);
        $this->assertSame('2026-10-25', $n->metadata['date']);
        $this->travelTo(CarbonImmutable::parse('2026-10-24T04:00:00Z'));
        $this->assertSame(0, Generate::run($o->public_id)['created']);
    }

    public function test_no_show_result_is_durable_and_present_checkin_does_not_spam(): void
    {
        [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
        $this->travelTo($r->session->starts_at);
        $this->postJson($this->base($o)."/sessions/{$r->session->public_id}/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $this->assertSame(0, PlatformNotification::where('type', 'attendance.result')->count());
    }
}
