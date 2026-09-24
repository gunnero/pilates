<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotification;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformNotification;
use App\Models\StudioAnnouncement;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Events;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

final class CommunicationConcurrencyTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    public static function cases(): array
    {
        return [['register'], ['read'], ['announcement'], ['push'], ['queue'], ['promotion'], ['cancellation']];
    }

    #[DataProvider('cases')]
    public function test_competing_workers_serialize_real_postgresql_operations(string $case): void
    {
        [$o, $owner, , $m, $user, $r] = $this->notificationFixture(1);
        $base = $this->base($o);
        $memberToken = $user->createToken('race-member', ['*'], now()->addDay())->plainTextToken;
        $staffToken = $owner->createToken('race-staff', ['*'], now()->addDay())->plainTextToken;
        $task = ['kind' => $case, 'now' => now()->toISOString(), 'body' => [], 'token' => $memberToken];
        if (in_array($case, ['register', 'push', 'queue'], true)) {
            $task['path'] = '/api/v1/member/organizations/'.$o->public_id.'/devices';
            $task['body'] = ['token' => 'ExpoPushToken[concurrency_fixture_device]', 'platform' => 'android', 'installation_id' => (string) Str::uuid7()];
            if ($case !== 'register') {
                $this->app['auth']->forgetGuards();
                $this->postJson($task['path'], $task['body'], ['Authorization' => 'Bearer '.$memberToken])->assertCreated();
                DB::transaction(fn () => Events::reservation($r, NotificationType::SessionChanged, (string) Str::uuid7()));
                $task['id'] = NotificationDelivery::where('channel', 'push')->firstOrFail()->id;
                if ($case === 'queue') {
                    for ($i = 0; $i < 2; $i++) {
                        DeliverNotification::dispatch($task['id'])->onConnection('database')->onQueue('notifications-race');
                    }
                }
            }
        } elseif ($case === 'read') {
            $n = PlatformNotification::firstOrFail();
            Delivery::run($n->delivery->id);
            $task['path'] = "$base/notifications/$n->public_id/read";
        } elseif ($case === 'announcement') {
            $body = ['request_id' => (string) Str::uuid7(), 'title_mk' => 'Тест', 'title_en' => 'Test', 'message_mk' => 'Тест порака', 'message_en' => 'Test message'];
            $id = $this->actingAs($owner)->postJson("$base/announcements", $body)->assertStatus(202)->json('data.id');
            $task['id'] = StudioAnnouncement::where('public_id', $id)->firstOrFail()->id;
        } elseif ($case === 'promotion') {
            $waiting = $this->notificationMember($o);
            $this->postJson("$base/sessions/{$r->session->public_id}/reservations", ['member_id' => $waiting->public_id, 'allow_waiting' => true])->assertOk();
            $task['path'] = "$base/reservations/$r->public_id/cancel";
            $task['token'] = $staffToken;
        } else {
            $task['path'] = "$base/sessions/{$r->session->public_id}/cancel";
            $task['token'] = $staffToken;
        }
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($o->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $worker = new Process([PHP_BINARY, base_path('tests/Support/communication-worker.php'), (string) $i], base_path(), null, json_encode($task, JSON_THROW_ON_ERROR), 30);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE application_name LIKE 'gt-communication-race-%' AND wait_event_type='Lock'")->n;
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            }
            if ($case === 'queue') {
                $this->assertSame(0, DB::table('jobs')->where('queue', 'notifications-race')->count());
                $this->assertSame(0, DB::table('failed_jobs')->count());
            }
            match ($case) {
                'register' => $this->assertDatabaseCount('device_push_tokens', 1),
                'read' => $this->assertDatabaseCount('notification_reads', 1),
                'announcement' => $this->assertSame(1, PlatformNotification::where('type', 'studio.announcement')->count()),
                'promotion' => $this->assertSame(1, PlatformNotification::where('type', 'waiting.promoted')->count()),
                'cancellation' => $this->assertSame(1, PlatformNotification::where('type', 'session.cancelled')->count()),
                default => $this->assertSame(1, DB::table('notification_delivery_attempts')->where('notification_delivery_id', $task['id'])->count()),
            };
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
