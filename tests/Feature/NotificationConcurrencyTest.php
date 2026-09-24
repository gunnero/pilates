<?php

namespace Tests\Feature;

use App\Models\MemberMembership;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\PlatformNotification;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinanceFixtures;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class NotificationConcurrencyTest extends TestCase
{
    use DatabaseMigrations,FinanceFixtures,NotificationFixtures;

    public static function cases(): array
    {
        return [['reminder'], ['expiry'], ['event'], ['delivery']];
    }

    #[DataProvider('cases')]
    public function test_real_competing_processes_are_idempotent(string $case): void
    {
        if ($case === 'expiry') {
            [$o,$u,$a,$m,$plan,$base] = $this->financeFixture();
            $id = $this->assignFinance($base, $m, $plan);
            $source = MemberMembership::where('public_id', $id)->firstOrFail()->id;
        } else {
            [$o,$u,$a,$m,$recipient,$r] = $this->notificationFixture();
            $source = $r->id;
            if ($case === 'reminder') {
                $this->travelTo($r->session->starts_at->subHours(23));
            }
            if ($case === 'delivery') {
                $source = NotificationDelivery::firstOrFail()->id;
            }
        }
        $workers = [];
        DB::beginTransaction();
        try {
            Organization::whereKey($o->id)->lockForUpdate()->firstOrFail();
            for ($i = 0; $i < 2; $i++) {
                $w = new Process([PHP_BINARY, base_path('tests/Support/notification-worker.php'), $case, $o->public_id, (string) $source, (string) $i, now()->toISOString()], base_path(), null, null, 30);
                $w->start();
                $workers[] = $w;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE application_name LIKE 'gt-notification-race-%' AND wait_event_type='Lock'")->n;
                if ($blocked === 2) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            foreach ($workers as $w) {
                $w->wait();
                $this->assertSame(0, $w->getExitCode(), $w->getErrorOutput());
            }
            $type = match ($case) {
                'reminder' => NotificationType::SessionReminder,'expiry' => NotificationType::MembershipExpiring,default => NotificationType::ReservationConfirmed
            };
            $this->assertSame(1, PlatformNotification::where('type', $type->value)->count());
            $this->assertSame(PlatformNotification::count(), NotificationDelivery::count());
            if ($case === 'delivery') {
                $this->assertDatabaseCount('notification_delivery_attempts', 1);
                $this->assertDatabaseHas('notification_deliveries', ['state' => 'delivered', 'attempts' => 1]);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }foreach ($workers as $w) {
                if ($w->isRunning()) {
                    $w->stop();
                }
            }
        }
    }
}
