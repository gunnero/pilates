<?php

namespace Tests\Feature;

use App\Contracts\Notifications\PushProvider;
use App\Jobs\DeliverNotification;
use App\Jobs\FanOutAnnouncement;
use App\Mail\NotificationMessage;
use App\Models\DevicePushToken;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformNotification;
use App\Models\StudioAnnouncement;
use App\Models\User;
use App\Support\Notifications\Announcements;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Events;
use App\Support\Notifications\NotificationType;
use App\Support\Notifications\Receipts;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

final class CommunicationTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    private function registration(): array
    {
        [$o, $owner, $staff, $m, $user, $r] = $this->notificationFixture();
        $token = $user->createToken('test-native', ['member.read', 'member.write', 'notifications.view', 'notifications.read', 'account.security'], now()->addDays(1));
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken];
        $body = ['token' => 'ExpoPushToken[test_device_registration_123]', 'platform' => 'ios', 'installation_id' => (string) Str::uuid7()];
        $base = '/api/v1/member/organizations/'.$o->public_id.'/devices';
        $id = $this->postJson($base, $body, $headers)->assertCreated()->json('data.id');

        return [$o, $owner, $m, $user, $r, $headers, $body, $base, $id, $token];
    }

    public function test_device_is_encrypted_owned_idempotent_and_logout_invalidates_delivery(): void
    {
        [$o, , $m, , $r, $headers, $body, $base, $id] = $this->registration();
        $this->postJson($base, $body, $headers)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('device_push_tokens', 1);
        $this->assertNotSame($body['token'], DB::table('device_push_tokens')->value('token_ciphertext'));
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, (string) Str::uuid7()));
        $d = NotificationDelivery::where('channel', 'push')->firstOrFail();
        $this->postJson('/api/v1/member/token/logout', [], $headers)->assertOk();
        $this->assertNull(DevicePushToken::firstOrFail()->access_token_id);
        $this->assertSame('suppressed', Delivery::run($d->id));
        $this->assertDatabaseHas('audit_events', ['organization_id' => $o->id, 'action' => 'notification.device_registered', 'subject_id' => $m->public_id]);
    }

    public function test_fake_push_receipt_revokes_invalid_token_without_exposing_payload(): void
    {
        [, , , , $r] = $this->registration();
        $fake = new class implements PushProvider
        {
            public array $sent = [];

            public function send(array $messages): array
            {
                $this->sent = $messages;

                return [['status' => 'accepted', 'id' => 'safe-ticket']];
            }

            public function receipts(array $ids): array
            {
                return ['safe-ticket' => ['status' => 'error', 'error' => 'invalid_device']];
            }
        };
        $this->app->instance(PushProvider::class, $fake);
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, (string) Str::uuid7()));
        $d = NotificationDelivery::where('channel', 'push')->firstOrFail();
        $this->assertSame('awaiting_receipt', Delivery::run($d->id));
        $this->assertSame('skipped', Delivery::run($d->id));
        $this->assertCount(1, $fake->sent);
        $this->assertSame(['url'], array_keys($fake->sent[0]['data']));
        $this->assertStringStartsWith('gtstudio://notification/', $fake->sent[0]['data']['url']);
        $this->travel(16)->minutes();
        $this->assertSame(1, Receipts::run());
        $this->assertNotNull(DevicePushToken::firstOrFail()->revoked_at);
        $this->assertDatabaseHas('notification_push_receipts', ['state' => 'failed', 'error_code' => 'invalid_device']);
    }

    public function test_announcements_are_queued_bounded_idempotent_and_cancellable(): void
    {
        [$o, $owner] = $this->notificationFixture();
        $base = $this->base($o).'/announcements';
        $body = ['request_id' => (string) Str::uuid7(), 'title_mk' => 'Студио', 'title_en' => 'Studio', 'message_mk' => 'Ново известување.', 'message_en' => 'A new announcement.'];
        $id = $this->actingAs($owner)->postJson($base, $body)->assertStatus(202)->json('data.id');
        $this->postJson($base, $body)->assertStatus(202)->assertJsonPath('data.id', $id);
        $this->assertSame(0, PlatformNotification::where('type', 'studio.announcement')->count());
        $a = StudioAnnouncement::where('public_id', $id)->firstOrFail();
        $this->assertSame(1, Announcements::run($a->id));
        $this->assertSame(0, Announcements::run($a->id));
        $n = PlatformNotification::where('type', 'studio.announcement')->firstOrFail();
        $this->assertSame('A new announcement.', $n->message_en);
        $this->postJson("$base/$id/cancel", [])->assertOk();
        $this->assertSame('suppressed', Delivery::run($n->delivery->id));
        $this->postJson($base, array_replace($body, ['request_id' => (string) Str::uuid7(), 'message_en' => '<script>bad</script>']))->assertUnprocessable();
    }

    public function test_partial_preferences_and_mark_all_read_are_recipient_scoped(): void
    {
        [$o, , , , $user, $r] = $this->notificationFixture();
        $base = $this->base($o).'/notifications';
        $this->actingAs($user)->patchJson($base.'/preferences', ['email_enabled' => true, 'session_changes_enabled' => false])->assertOk()->assertJsonPath('data.reminders_enabled', true)->assertJsonPath('data.email_enabled', true);
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, (string) Str::uuid7()));
        $this->assertSame(0, NotificationDelivery::where('channel', 'email')->count());
        foreach (NotificationDelivery::pluck('id') as $id) {
            Delivery::run($id);
        }
        $this->getJson($base.'/unread-count')->assertOk()->assertJsonPath('data.unread_count', 2);
        $this->postJson($base.'/read-all', [])->assertOk();
        $this->postJson($base.'/read-all', [])->assertOk();
        $this->getJson($base.'/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_device_cannot_be_reassigned_to_another_account_or_mutated_directly(): void
    {
        [$o, , , , , $headers, $body, $base, $id] = $this->registration();
        $other = User::factory()->create();
        $otherMember = $this->notificationMember($o, $other);
        $otherToken = $other->createToken('other', ['*'], now()->addDay());
        $this->app['auth']->forgetGuards();
        $otherHeaders = ['Authorization' => 'Bearer '.$otherToken->plainTextToken];
        $this->postJson($base, $body, $otherHeaders)->assertConflict();
        $this->deleteJson("$base/$id", [], $otherHeaders)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->deleteJson("$base/$id", [], $headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson($base, $body, $otherHeaders)->assertConflict();
        $this->rejected(fn () => DB::table('device_push_tokens')->update(['user_id' => $other->id, 'studio_member_id' => $otherMember->id]));
        $this->rejected(fn () => DB::table('push_token_owners')->update(['user_id' => $other->id]));
        $this->rejected(fn () => DB::table('device_push_tokens')->delete());
    }

    public function test_database_rejects_foreign_delivery_and_bad_channels_and_preserves_announcement_evidence(): void
    {
        [$o, , , , $r] = $this->registration();
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, (string) Str::uuid7()));
        $raw = (array) DB::table('notification_deliveries')->where('channel', 'push')->first();
        unset($raw['id']);
        $this->rejected(fn () => DB::table('notification_deliveries')->insert($raw));
        $foreign = User::factory()->create();
        $this->rejected(fn () => DB::table('notification_deliveries')->insert(array_replace($raw, ['destination_user_id' => $foreign->id])));
        $this->rejected(fn () => DB::table('notification_deliveries')->insert(array_replace($raw, ['channel' => 'sms'])));
        $this->rejected(fn () => DB::table('notification_deliveries')->insert(array_replace($raw, ['destination_key' => 'arbitrary'])));
        $this->rejected(fn () => DB::table('notification_deliveries')->where('channel', 'push')->update(['destination_user_id' => $foreign->id]));
        $this->assertSame($o->id, DevicePushToken::firstOrFail()->organization_id);
    }

    public function test_rollback_has_no_phantom_push_and_transient_transport_does_not_change_reservation(): void
    {
        [, , , , $r] = $this->registration();
        $before = PlatformNotification::count();
        DB::beginTransaction();
        Events::reservation($r, NotificationType::SessionChanged, 'rolled-back');
        DB::rollBack();
        $this->assertSame($before, PlatformNotification::count());
        $this->assertSame(0, NotificationDelivery::where('channel', 'push')->count());
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, 'committed'));
        $this->app->instance(PushProvider::class, new class implements PushProvider
        {
            public function send(array $messages): array
            {
                throw new \RuntimeException('Test transport unavailable');
            }

            public function receipts(array $ids): array
            {
                return [];
            }
        });
        $d = NotificationDelivery::where('channel', 'push')->firstOrFail();
        $this->assertSame('retry', Delivery::run($d->id));
        $this->assertSame('active', $r->fresh()->status);
        $this->assertSame('skipped', Delivery::run($d->id));
        $this->assertDatabaseHas('notification_delivery_attempts', ['notification_delivery_id' => $d->id, 'outcome' => 'retry', 'error_code' => 'transient']);
    }

    public function test_announcement_member_denial_foreign_group_and_immutable_content(): void
    {
        [$o, $owner, , , $user] = $this->notificationFixture();
        $base = $this->base($o).'/announcements';
        $body = ['request_id' => (string) Str::uuid7(), 'title_mk' => 'Тест', 'title_en' => 'Test', 'message_mk' => 'Порака', 'message_en' => 'Message'];
        $this->actingAs($user)->postJson($base, $body)->assertNotFound();
        $this->getJson($base)->assertNotFound();
        $this->actingAs($owner)->postJson($base, $body + ['group_id' => (string) Str::uuid7()])->assertNotFound();
        $this->postJson($base, $body)->assertStatus(202);
        $this->rejected(fn () => DB::table('studio_announcements')->update(['message_en' => 'Forged']));
        $this->rejected(fn () => DB::table('studio_announcements')->update(['cursor_id' => -1]));
        $this->rejected(fn () => DB::table('studio_announcements')->delete());
    }

    public function test_email_is_disabled_locally_even_with_an_enabled_flag(): void
    {
        [$o, , , , $user, $r] = $this->notificationFixture();
        $this->actingAs($user)->patchJson($this->base($o).'/notifications/preferences', ['email_enabled' => true])->assertOk();
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, 'email'));
        config(['notifications.email_enabled' => true]);
        Mail::fake();
        $d = NotificationDelivery::where('channel', 'email')->firstOrFail();
        $this->assertSame('suppressed', Delivery::run($d->id));
        Mail::assertNothingSent();
    }

    public function test_one_account_can_register_the_device_in_two_studios_without_reassignment(): void
    {
        [, , , $user, , $headers, $body] = $this->registration();
        $other = Organization::create(['name' => 'Second studio']);
        $this->notificationMember($other, $user);
        $this->postJson('/api/v1/member/organizations/'.$other->public_id.'/devices', $body, $headers)->assertCreated();
        $this->assertDatabaseCount('push_token_owners', 1);
        $this->assertDatabaseCount('device_push_tokens', 2);
    }

    public function test_transient_receipt_retries_the_delivery_and_success_is_not_claimed_before_receipt(): void
    {
        [, , , , $r] = $this->registration();
        $this->app->instance(PushProvider::class, new class implements PushProvider
        {
            private int $sent = 0;

            public function send(array $messages): array
            {
                return [['status' => 'accepted', 'id' => 'ticket-'.++$this->sent]];
            }

            public function receipts(array $ids): array
            {
                $result = [];
                foreach ($ids as $id) {
                    $result[$id] = $id === 'ticket-1' ? ['status' => 'error', 'error' => 'transient'] : ['status' => 'accepted'];
                }

                return $result;
            }
        });
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, 'receipt-retry'));
        $d = NotificationDelivery::where('channel', 'push')->firstOrFail();
        $this->assertSame('awaiting_receipt', Delivery::run($d->id));
        $this->assertNull($d->fresh()->delivered_at);
        $this->travel(16)->minutes();
        Receipts::run();
        $this->assertSame('retry', $d->fresh()->state);
        $this->travel(2)->minutes();
        $this->assertSame('awaiting_receipt', Delivery::run($d->id));
        $this->travel(16)->minutes();
        Receipts::run();
        $this->assertSame('delivered', $d->fresh()->state);
        $this->assertDatabaseCount('notification_push_receipts', 2);
        $this->assertSame(2, $d->fresh()->attempts);
    }

    public function test_queued_email_uses_the_verified_account_and_localized_plain_text_with_a_fake_transport(): void
    {
        [$o, , $staff, $m, $user, $r] = $this->notificationFixture();
        $m->forceFill(['preferred_locale' => 'en'])->save();
        $this->actingAs($user)->patchJson($this->base($o).'/notifications/preferences', ['email_enabled' => true])->assertOk();
        DB::transaction(fn () => Events::reservation($r->fresh(), NotificationType::SessionChanged, 'localized-email'));
        Mail::fake();
        config(['notifications.email_enabled' => true]);
        $this->app['env'] = 'production';
        $d = NotificationDelivery::where('channel', 'email')->firstOrFail();
        (new DeliverNotification($d->id))->handle();
        $this->app['env'] = 'testing';
        $this->assertSame('delivered', $d->fresh()->state);
        Mail::assertSent(NotificationMessage::class, fn ($mail) => $mail->hasTo($user->email) && $mail->heading === $d->notification->title_en);
        $this->assertStringNotContainsString('<script>', (new NotificationMessage('Title', 'Plain text'))->render());
    }

    public function test_invalid_device_ticket_is_a_classified_terminal_failure(): void
    {
        [, , , , $r] = $this->registration();
        $this->app->instance(PushProvider::class, new class implements PushProvider
        {
            public function send(array $messages): array
            {
                return [['status' => 'error', 'error' => 'invalid_device']];
            }

            public function receipts(array $ids): array
            {
                return [];
            }
        });
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, 'invalid-ticket'));
        $d = NotificationDelivery::where('channel', 'push')->firstOrFail();
        $this->assertSame('failed', Delivery::run($d->id));
        $this->assertSame('invalid_device', $d->fresh()->error_code);
        $this->assertNotNull(DevicePushToken::firstOrFail()->revoked_at);
        $this->assertSame('skipped', Delivery::run($d->id));
    }

    public function test_waiting_member_receives_cancellation_without_changing_fifo_evidence(): void
    {
        [$o, $owner, , , , $r] = $this->notificationFixture(1);
        $waiting = $this->notificationMember($o, User::factory()->create());
        $base = $this->base($o);
        $this->actingAs($owner)->postJson("$base/sessions/{$r->session->public_id}/reservations", ['member_id' => $waiting->public_id, 'allow_waiting' => true])->assertOk();
        $entry = DB::table('waiting_list_entries')->first();
        $this->assertSame(1, PlatformNotification::where('type', 'waiting.joined')->count());
        $this->postJson("$base/sessions/{$r->session->public_id}/cancel", [])->assertOk();
        $this->postJson("$base/sessions/{$r->session->public_id}/cancel", [])->assertOk();
        $notices = PlatformNotification::where('type', 'waiting.session_cancelled')->get();
        $this->assertCount(1, $notices);
        $this->assertSame($waiting->id, $notices->first()->studio_member_id);
        $this->assertSame('delivered', Delivery::run($notices->first()->delivery->id));
        $this->assertEquals($entry, DB::table('waiting_list_entries')->first());
    }

    public function test_group_announcements_exclude_unrelated_members_and_batch_all_members(): void
    {
        [$o, $owner, , $member, , $r] = $this->notificationFixture();
        for ($i = 0; $i < 101; $i++) {
            $this->notificationMember($o);
        }
        $body = ['request_id' => (string) Str::uuid7(), 'title_mk' => 'Тест', 'title_en' => 'Test', 'message_mk' => 'Порака', 'message_en' => 'Message'];
        $base = $this->base($o).'/announcements';
        $this->actingAs($owner)->postJson($base, $body + ['group_id' => $r->session->group->public_id])->assertStatus(202);
        $group = StudioAnnouncement::firstOrFail();
        $this->assertSame(1, Announcements::run($group->id));
        $this->assertSame($member->id, PlatformNotification::where('announcement_id', $group->id)->sole()->studio_member_id);
        $this->postJson($base, array_replace($body, ['request_id' => (string) Str::uuid7()]))->assertStatus(202);
        $all = StudioAnnouncement::orderByDesc('id')->firstOrFail();
        $this->assertSame(100, Announcements::run($all->id));
        $this->assertSame('pending', $all->fresh()->state);
        $this->assertSame(2, Announcements::run($all->id));
        $this->assertSame(0, Announcements::run($all->id));
        $this->assertSame(102, PlatformNotification::where('announcement_id', $all->id)->count());
        $this->assertSame('complete', $all->fresh()->state);
    }

    public function test_revoked_device_cannot_bypass_the_account_registration_limit(): void
    {
        [, , , , , $headers, $body, $base, $id] = $this->registration();
        $this->deleteJson("$base/$id", [], $headers)->assertOk();
        for ($i = 0; $i < 10; $i++) {
            $next = array_replace($body, ['token' => 'ExpoPushToken[bounded_device_'.$i.']']);
            $this->postJson($base, $next, $headers)->assertCreated();
        }
        $this->postJson($base, $body, $headers)->assertTooManyRequests();
        $this->assertSame(10, DevicePushToken::whereNull('revoked_at')->count());
        $this->postJson($base, $next, $headers)->assertCreated();
    }

    public function test_queue_sweep_dispatches_durable_ids_and_reports_safe_counts(): void
    {
        [$o, $owner] = $this->notificationFixture();
        $this->actingAs($owner)->postJson($this->base($o).'/announcements', ['request_id' => (string) Str::uuid7(), 'title_mk' => 'Тест', 'title_en' => 'Test', 'message_mk' => 'Приватна порака', 'message_en' => 'Private announcement'])->assertStatus(202);
        Queue::fake();
        $this->assertSame(0, Artisan::call('gt:notifications:queue'));
        $output = Artisan::output();
        $counts = json_decode(trim($output), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $counts['announcements']);
        $this->assertGreaterThan(0, $counts['deliveries']);
        $this->assertSame(0, $counts['errors']);
        $this->assertStringNotContainsString('Private announcement', $output);
        Queue::assertPushed(FanOutAnnouncement::class, 1);
        Queue::assertPushed(DeliverNotification::class, $counts['deliveries']);
    }

    public function test_expired_registrations_do_not_create_push_destinations(): void
    {
        [, , , , $r, $headers, $body, $base, , $login] = $this->registration();
        $login->accessToken->forceFill(['expires_at' => now()->subMinute()])->save();
        DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, 'expired-login'));
        $this->assertSame(0, NotificationDelivery::where('channel', 'push')->count());
    }

    private function rejected(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('PostgreSQL accepted invalid communication evidence');
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), ['23514', '23503', '23505']);
        }
    }
}
