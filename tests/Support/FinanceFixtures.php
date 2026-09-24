<?php

namespace Tests\Support;

use App\Models\Organization;
use App\Models\StudioMember;
use App\Models\User;
use Illuminate\Support\Str;

trait FinanceFixtures
{
    use OrganizationFixtures;

    protected function financeFixture(): array
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(12, 0));
        $o = Organization::create(['name' => 'Finance fixture']);
        $u = User::factory()->create();
        $a = $this->membership($u, $o);
        $this->actingAs($u);
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'first_name' => 'Cash', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $url = $this->base($o);
        $plan = $this->postJson($url.'/membership-plans', $this->planData())->assertCreated()->json('data.id');

        return [$o, $u, $a, $m, $plan, $url];
    }

    protected function planData(): array
    {
        return ['name' => 'Monthly', 'price_minor' => 150000, 'duration_unit' => 'months', 'duration_count' => 1, 'status' => 'active'];
    }

    protected function assignFinance(string $url, StudioMember $m, string $plan, string $start = '2026-09-01'): string
    {
        return $this->postJson($url.'/memberships', ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => $start])->assertCreated()->json('data.id');
    }

    protected function payData(string $membership, int $amount = 150000): array
    {
        return ['membership_id' => $membership, 'amount_minor' => $amount, 'paid_at' => '2026-09-24T10:00:00Z', 'request_id' => (string) Str::uuid7()];
    }
}
