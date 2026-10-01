<?php

namespace Tests\Feature;

use App\Contracts\Accounts\InvitationDelivery;
use App\Models\MemberInvitation;
use App\Models\Organization;
use App\Support\SelfService\AccountSecurity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

class MemberIntegrityTest extends TestCase
{
    use DatabaseMigrations,NotificationFixtures;

    private function rejected(\Closure $op): void
    {
        try {
            DB::transaction($op);
            $this->fail('Invalid member evidence accepted');
        } catch (QueryException $e) {
            $this->assertContains($e->getCode(), ['23514', '23503', '23505']);
        }
    }

    public function test_postgresql_protects_invitation_state_ownership_links_and_security_audit(): void
    {
        [$o,$owner,$a,$m,$u,$r] = $this->notificationFixture();
        $target = $this->notificationMember($o);
        $capture = new class implements InvitationDelivery
        {
            public string $url = '';

            public function send(string $email, string $url, string $locale): void
            {
                $this->url = $url;
            }
        };
        $this->app->instance(InvitationDelivery::class, $capture);
        $this->postJson($this->base($o)."/members/$target->public_id/invitations", ['email' => 'new@example.test'])->assertCreated();
        $i = MemberInvitation::firstOrFail();
        $raw = (array) DB::table('member_invitations')->first();
        unset($raw['id']);
        $foreign = Organization::create(['name' => 'Foreign']);
        $other = $this->notificationMember($o);
        foreach ([['organization_id' => $foreign->id], ['token_hash' => 'raw-token'], ['email' => 'UPPER@example.test'], ['status' => 'accepted'], ['expires_at' => now()->addDays(3)], ['status' => 'unknown']] as $patch) {
            $this->rejected(fn () => DB::table('member_invitations')->insert(array_replace($raw, ['public_id' => (string) Str::uuid7(), 'token_hash' => hash('sha256', Str::random()), 'studio_member_id' => $other->id], $patch)));
        }
        $this->rejected(fn () => DB::table('member_invitations')->insert(array_replace($raw, ['public_id' => (string) Str::uuid7(), 'token_hash' => hash('sha256', Str::random())])));
        $this->rejected(fn () => DB::table('member_invitations')->where('id', $i->id)->update(['email' => 'hijack@example.test']));
        $this->rejected(fn () => DB::table('member_invitations')->where('id', $i->id)->update(['status' => 'accepted', 'accepted_user_id' => $u->id, 'consumed_at' => now()]));
        $this->rejected(fn () => DB::table('studio_members')->where('id', $m->id)->update(['user_id' => $owner->id]));
        $this->rejected(fn () => DB::table('studio_members')->where('id', $m->id)->update(['user_id' => null]));
        $this->rejected(fn () => DB::table('studio_members')->where('id', $other->id)->update(['user_id' => $u->id]));
        parse_str(parse_url($capture->url, PHP_URL_FRAGMENT), $data);
        $this->postJson('/auth/activate', $data + ['password' => 'Strong-password-123!', 'password_confirmation' => 'Strong-password-123!'])->assertOk();
        $this->rejected(fn () => DB::table('member_invitations')->where('id', $i->id)->update(['status' => 'pending', 'accepted_user_id' => null, 'consumed_at' => null]));
        $this->rejected(fn () => DB::table('member_invitations')->delete());
        AccountSecurity::run($u->email, fn () => AccountSecurity::audit($u, 'tokens_revoked'));
        $this->rejected(fn () => DB::table('account_security_events')->update(['action' => 'password_reset']));
        $this->rejected(fn () => DB::table('account_security_events')->delete());
        $this->rejected(fn () => DB::table('audit_events')->update(['action' => 'tampered']));
        $this->assertStringNotContainsString($data['token'], DB::table('member_invitations')->get()->toJson());
        $audit = DB::table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString($data['token'], $audit);
        $this->assertStringNotContainsString('new@example.test', $audit);
    }
}
