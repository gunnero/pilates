<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\PlatformNotification;
use App\Models\User;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Generate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class NotificationFinanceTest extends TestCase
{
    use DatabaseMigrations,FinanceFixtures;

    public function test_expiry_local_dates_repeated_batches_and_cash_privacy(): void
    {
        [$o,$u,$a,$m,$plan,$base] = $this->financeFixture();
        $memberUser = User::factory()->create();
        $m->user_id = $memberUser->id;
        $m->save();
        $id = $this->assignFinance($base, $m, $plan);
        $this->assertSame(1, Generate::run($o->public_id, 1)['created']);
        $this->assertSame(0, Generate::run($o->public_id, 1)['created']);
        $payment = $this->postJson("$base/cash-payments", $this->payData($id) + ['note' => 'private cash note'])->assertCreated()->json('data.id');
        $receipt = PlatformNotification::where('type', 'cash.recorded')->firstOrFail();
        $this->assertSame('delivered', Delivery::run($receipt->delivery->id));
        $this->postJson("$base/cash-payments/$payment/reverse", ['reason' => 'wrong_amount'])->assertOk();
        foreach (NotificationDelivery::pluck('id') as $delivery) {
            Delivery::run($delivery);
        }
        $json = $this->actingAs($memberUser)->getJson("$base/notifications")->assertOk()->assertJsonCount(4, 'data')->getContent();
        foreach (['150000', 'private cash note', 'balance', 'price_minor', 'amount_minor', 'recorded_by'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
        $this->actingAs($u)->getJson("$base/notifications")->assertJsonCount(0, 'data');
        // Skopje is already October 1 while UTC remains September 30.
        $this->travelTo(CarbonImmutable::parse('2026-09-30T22:30:00Z'));
        $this->assertSame(1, Generate::run($o->public_id)['created']);
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->assertSame(1, PlatformNotification::where('type', 'membership.expired')->count());
    }

    public function test_cancelled_membership_suppresses_pending_messages_and_command_bounds(): void
    {
        [$o,$u,$a,$m,$plan,$base] = $this->financeFixture();
        $id = $this->assignFinance($base, $m, $plan);
        $this->postJson("$base/memberships/$id/cancel", ['reason' => 'mistake'])->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->assertSame('suppressed', Delivery::run(NotificationDelivery::firstOrFail()->id));
        $this->artisan('gt:notifications:generate', ['organization' => $o->public_id, '--limit' => 201])->assertFailed();
        $this->artisan('gt:notifications:generate', ['organization' => $o->public_id])->assertSuccessful();
        $this->artisan('gt:notifications:deliver', ['--limit' => 201])->assertFailed();
        $this->artisan('gt:notifications:deliver')->assertSuccessful();
    }

    public function test_future_membership_activation_has_one_durable_event(): void
    {
        [$o,$u,$a,$m,$plan,$base] = $this->financeFixture();
        $this->assignFinance($base, $m, $plan, '2026-10-01');
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T00:00:00Z'));
        $this->assertSame(1, Generate::run($o->public_id)['created']);
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->assertSame(1,PlatformNotification::where('type','membership.activated')->count());
    }
}
