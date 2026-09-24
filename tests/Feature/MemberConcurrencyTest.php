<?php

namespace Tests\Feature;

use App\Contracts\Accounts\InvitationDelivery;
use App\Models\MemberInvitation;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\StudioMember;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class MemberConcurrencyTest extends TestCase
{
    use DatabaseMigrations,NotificationFixtures;

    public static function cases(): array
    {
        return [['activation'], ['link'], ['capacity'], ['attendance'], ['reset']];
    }

    #[DataProvider('cases')]
    public function test_competing_member_operations_preserve_invariants(string $case): void
    {
        [$o,$owner,$access,$m,$u,$r] = $this->notificationFixture(2);
        $base = "/api/v1/member/organizations/$o->public_id";
        $email = $u->email;
        if (in_array($case, ['activation', 'link'])) {
            $capture = new class implements InvitationDelivery
            {
                public string $url = '';

                public function send(string $email, string $url, string $locale): void
                {
                    $this->url = $url;
                }
            };
            $this->app->instance(InvitationDelivery::class, $capture);
            $target = $this->notificationMember($o);
            $email = 'race@example.test';
            $this->postJson($this->base($o)."/members/$target->public_id/invitations", ['email' => $email])->assertCreated();
            parse_str(parse_url($capture->url, PHP_URL_FRAGMENT), $payload);
            $payload += ['password' => 'Strong-password-123!', 'password_confirmation' => 'Strong-password-123!'];
            $tasks = [['path' => '/api/v1/member-auth/activate', 'data' => $payload], ['path' => '/api/v1/member-auth/activate', 'data' => $payload]];
            if ($case === 'link') {
                // Different valid invitations compete to link the same existing account within a studio.
                User::factory()->create(['email' => $email, 'password' => 'Strong-password-123!']);
                $second = $this->notificationMember($o);
                $this->postJson($this->base($o)."/members/$second->public_id/invitations", ['email' => $email])->assertCreated();
                parse_str(parse_url($capture->url, PHP_URL_FRAGMENT), $other);
                $tasks[1]['data'] = array_replace($payload, $other);
            }
        } elseif ($case === 'reset') {
            $token = Password::createToken($u);
            $payload = ['email' => $email, 'token' => $token, 'password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!'];
            $tasks = array_fill(0, 2, ['path' => '/api/v1/member-auth/reset-password', 'data' => $payload]);
        } elseif ($case === 'capacity') {
            $v = User::factory()->create();
            $w = User::factory()->create();
            $this->notificationMember($o, $v);
            $this->notificationMember($o, $w);
            $tasks = [['user' => $v->id, 'path' => "$base/sessions/{$r->session->public_id}/reservations", 'data' => []], ['user' => $w->id, 'path' => "$base/sessions/{$r->session->public_id}/reservations", 'data' => []]];
        } else {
            $this->travelTo($r->session->starts_at->subMinutes(10));
            $tasks = [['user' => $u->id, 'path' => "$base/reservations/$r->public_id/cancel", 'data' => []], ['user' => $owner->id, 'path' => $this->base($o)."/sessions/{$r->session->public_id}/attendance", 'data' => ['member_id' => $m->public_id, 'status' => 'present']]];
        }
        $workers = [];
        DB::beginTransaction();
        try {
            if (in_array($case, ['activation', 'link', 'reset'])) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['gt-account:'.$email]);
            } else {
                Organization::whereKey($o->id)->lockForUpdate()->firstOrFail();
            }
            foreach ($tasks as $i => $task) {
                $task['clock'] = now()->toISOString();
                $p = new Process([PHP_BINARY, base_path('tests/Support/self-service-worker.php'), (string) $i], base_path(), null, json_encode($task, JSON_THROW_ON_ERROR), 30);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 10;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("SELECT count(*) n FROM pg_stat_activity WHERE application_name LIKE 'gt-self-service-race-%' AND wait_event_type='Lock'")->n;
                if ($blocked === 2) {
                    break;
                }usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked);
            DB::commit();
            $codes = [];
            foreach ($workers as $p) {
                $p->wait();
                $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
                $codes[] = trim($p->getOutput());
            }sort($codes);
            $this->assertSame(['200', in_array($case, ['capacity', 'attendance']) ? '409' : '422'], $codes);
            if (in_array($case, ['activation', 'link'])) {
                $account = User::where('email', $email)->firstOrFail();
                $this->assertSame(1, StudioMember::where('organization_id', $o->id)->where('user_id', $account->id)->count());
                $this->assertSame(1, MemberInvitation::where('status', 'accepted')->count());
            }
            if ($case === 'reset') {
                $this->assertDatabaseCount('password_reset_tokens', 0);
                $this->assertDatabaseHas('account_security_events', ['action' => 'password_reset']);
            }
            if ($case === 'capacity') {
                $this->assertSame(2, Reservation::where('status', 'active')->count());
            }
            $this->artisan('gt:reservations:check')->assertSuccessful();
            $this->artisan('gt:attendance:check')->assertSuccessful();
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }foreach ($workers as $p) {
                if ($p->isRunning()) {
                    $p->stop();
                }
            }
        }
    }
}
