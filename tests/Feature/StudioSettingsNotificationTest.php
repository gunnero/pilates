<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\PlatformNotification;
use App\Support\Notifications\Delivery;
use App\Support\Notifications\Generate;
use App\Support\StudioSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class StudioSettingsNotificationTest extends TestCase
{
    use DatabaseMigrations, FinanceFixtures;

    public function test_expiry_warning_window_toggle_delivery_and_non_retroactive_membership_display(): void
    {
        [$o, , , $m, $plan, $base] = $this->financeFixture();
        $this->assignFinance($base, $m, $plan);
        $before = DB::table('member_memberships')->get()->toJson();
        $this->assertSame(7, StudioSettings::forOrganization($o)->membership_expiring_days);
        $this->patchJson($base.'/settings', ['expiry_warning_days' => 1, 'membership_expiring_days' => 1])->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->getJson($base.'/memberships?state=expiring')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($base.'/settings', ['expiry_warning_days' => 7, 'expiry_warnings_enabled' => false, 'membership_expiring_days' => 7])->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->getJson($base.'/memberships?state=expiring')->assertOk()->assertJsonCount(1, 'data');
        $this->patchJson($base.'/settings', ['expiry_warnings_enabled' => true])->assertOk();
        $this->assertSame(1, Generate::run($o->public_id)['created']);
        $n = PlatformNotification::where('type', 'membership.expiring')->firstOrFail();
        $d = NotificationDelivery::where('platform_notification_id', $n->id)->firstOrFail();
        $this->patchJson($base.'/settings', ['expiry_warnings_enabled' => false])->assertOk();
        $this->assertSame('suppressed', Delivery::run($d->id));
        $this->patchJson($base.'/settings', ['expiry_warnings_enabled' => true, 'expiry_warning_days' => 30])->assertOk();
        $this->assertSame(0, Generate::run($o->public_id)['created']);
        $this->assertSame($before, DB::table('member_memberships')->get()->toJson());
        $this->assertSame(1, PlatformNotification::where('type', 'membership.expiring')->count());
    }
}
