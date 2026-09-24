<?php

namespace Tests\Support;

use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\User;

trait NotificationFixtures
{
    use SessionFixtures;

    protected function notificationFixture(int $capacity = 12): array
    {
        [$o,$u,$a,$l,$t,$g] = $this->sessionFixture();
        $id = $this->postJson($this->base($o).'/sessions', $this->sessionData($g, $l, $t, ['capacity' => $capacity]))->assertCreated()->json('data.id');
        $recipient = User::factory()->create();
        $m = $this->notificationMember($o, $recipient);
        $rid = $this->postJson($this->base($o)."/sessions/$id/reservations", ['member_id' => $m->public_id])->assertOk()->json('data.reservation.id');

        return [$o, $u, $a, $m, $recipient, Reservation::where('public_id', $rid)->firstOrFail()];
    }

    protected function notificationMember($o, ?User $u = null): StudioMember
    {
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'user_id' => $u?->id, 'first_name' => 'Notification', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => $u ? 'app' : 'offline'])->save();

        return $m;
    }
}
