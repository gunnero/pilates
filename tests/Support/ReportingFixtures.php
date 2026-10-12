<?php

namespace Tests\Support;

use App\Models\StudioMember;
use Illuminate\Support\Str;

trait ReportingFixtures
{
    use SessionFixtures;

    protected function reportFixture(): array
    {
        [$o,$u,$a,$l,$t,$g] = $this->sessionFixture();
        $url = $this->base($o);
        $id = $this->postJson($url.'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'first_name' => '=SUM(1,2)', 'last_name' => 'Македонски', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $this->postJson("$url/sessions/$id/reservations", ['member_id' => $m->public_id])->assertOk();
        $plan = $this->postJson($url.'/membership-plans', ['name' => '+План', 'price_minor' => 10000, 'duration_unit' => 'months', 'duration_count' => 1, 'status' => 'active'])->assertCreated()->json('data.id');
        $membership = $this->postJson($url.'/memberships', ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-09-01'])->assertCreated()->json('data.id');
        $payment = $this->postJson($url.'/cash-payments', ['membership_id' => $membership, 'amount_minor' => 4000, 'paid_at' => '2026-09-10T10:00:00Z', 'request_id' => (string) Str::uuid7()])->assertCreated()->json('data.id');

        return [$o, $u, $a, $id, $m, $payment];
    }
}
