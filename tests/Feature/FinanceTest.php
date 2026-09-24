<?php

namespace Tests\Feature;

use App\Models\CashPayment;
use App\Models\MemberMembership;
use App\Models\User;
use App\Support\MembershipPeriod;
use App\Support\StaffRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use DatabaseMigrations,FinanceFixtures;

    public function test_partial_multiple_exact_overpayment_reversal_and_retry(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.balance_minor', 150000);
        $body = $this->payData($id, 100000) + ['note' => 'Private cash note'];
        $p = $this->postJson("$url/cash-payments", $body)->assertCreated()->assertJsonPath('data.recorded_by.id', $u->public_id)->json('data.id');
        $this->postJson("$url/cash-payments", $body)->assertCreated()->assertJsonPath('data.id', $p);
        $this->postJson("$url/cash-payments", array_replace($body, ['amount_minor' => 1]))->assertStatus(409);
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.payment_status', 'partially_paid')->assertJsonPath('data.balance_minor', 50000);
        $this->postJson("$url/cash-payments", $this->payData($id, 50000))->assertCreated();
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.payment_status', 'paid');
        $this->postJson("$url/cash-payments", $this->payData($id, 1))->assertCreated();
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.payment_status', 'overpaid')->assertJsonPath('data.balance_minor', -1);
        $this->postJson("$url/cash-payments/$p/reverse", ['reason' => 'wrong_amount'])->assertOk()->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.reversal.recorded_by.id', $u->public_id);
        $this->postJson("$url/cash-payments/$p/reverse", ['reason' => 'other'])->assertStatus(409);
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.net_paid_minor', 50001)->assertJsonPath('data.balance_minor', 99999)->assertJsonPath('data.gross_paid_minor', 150001)->assertJsonPath('data.reversed_minor', 100000);
        $this->getJson("$url/cash-payments")->assertJsonCount(3, 'data')->assertJsonMissingPath('data.0.note');
        $this->assertStringNotContainsString('Private cash note', DB::table('audit_events')->get()->toJson());
        $this->artisan('gt:finance:check')->assertSuccessful();
        $this->getJson("$url/finance/metrics")->assertOk()->assertJsonPath('data.cash_today_minor', 50001)->assertJsonPath('data.cash_month_minor', 50001)->assertJsonPath('data.active', 1);
    }

    public function test_plan_lifecycle_snapshot_overlap_and_cancellation(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        $this->postJson("$url/memberships", ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-09-30'])->assertStatus(409);
        $this->patchJson("$url/membership-plans/$plan", array_replace($this->planData(), ['price_minor' => 180000, 'name' => 'New name']))->assertOk();
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.agreed_price_minor', 150000)->assertJsonPath('data.plan_name', 'Monthly')->assertJsonPath('data.ends_on', '2026-09-30');
        $future = $this->assignFinance($url, $m, $plan, '2026-10-01');
        $this->getJson("$url/memberships/$future")->assertJsonPath('data.status', 'upcoming')->assertJsonPath('data.agreed_price_minor', 180000);
        $this->postJson("$url/memberships/$id/cancel", ['reason' => 'member_request'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("$url/memberships/$id/cancel", ['reason' => 'other'])->assertOk();
        $this->postJson("$url/cash-payments", $this->payData($id))->assertStatus(409);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'membership.cancelled')->count());
        foreach (['inactive', 'archived'] as $state) {
            $this->patchJson("$url/membership-plans/$plan", array_replace($this->planData(), ['status' => $state]))->assertOk();
            $this->postJson("$url/memberships", ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2027-01-01'])->assertStatus(409);
        }
        $this->patchJson("$url/membership-plans/$plan", $this->planData())->assertStatus(409);
        $this->deleteJson("$url/membership-plans/$plan")->assertStatus(405);
    }

    public static function dateCases(): array
    {
        return [['2026-01-31', 'months', 1, '2026-02-27'], ['2024-01-31', 'months', 1, '2024-02-28'], ['2024-02-29', 'months', 12, '2025-02-27'], ['2026-09-01', 'days', 30, '2026-09-30'], ['2026-09-30', 'days', 1, '2026-09-30'], ['2026-11-01', 'months', 3, '2027-01-31']];
    }

    #[DataProvider('dateCases')]
    public function test_duration_semantics(string $start, string $unit, int $count, string $end): void
    {
        $this->assertSame($end, MembershipPeriod::end($start, $unit, $count));
    }

    public function test_local_dates_filters_and_boundaries(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        foreach (['active' => 1, 'expiring' => 1, 'expired' => 0, 'upcoming' => 0, 'cancelled' => 0] as $state => $n) {
            $this->getJson("$url/memberships?state=$state")->assertOk()->assertJsonCount($n, 'data');
        }
        $this->getJson("$url/memberships?payment_state=unpaid")->assertJsonCount(1, 'data');
        $this->travelTo(CarbonImmutable::parse('2026-09-30T22:00:00Z'));
        $this->getJson("$url/memberships/$id")->assertJsonPath('data.status', 'expired');
        $this->getJson("$url/memberships?per_page=101")->assertUnprocessable();
        $this->postJson("$url/membership-plans/$plan/preview", ['starts_on' => '2024-01-31'])->assertOk()->assertJsonPath('data.ends_on', '2024-02-28');
    }

    public function test_financial_visibility_and_independent_permissions(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        $body = $this->payData($id);
        $staff = User::factory()->create();
        $access = $this->membership($staff, $o, StaffRole::Trainer, ['studio.access', 'attendance.view'], 'selected');
        $this->actingAs($staff);
        $this->getJson("$url/memberships")->assertForbidden();
        $this->getJson("$url/cash-payments")->assertForbidden();
        $this->getJson("$url/membership-plans/$plan")->assertForbidden();
        $access->forceFill(['permissions' => ['studio.access', 'memberships.view']])->save();
        $this->getJson("$url/memberships/$id")->assertOk()->assertJsonMissingPath('data.agreed_price_minor')->assertJsonMissingPath('data.balance_minor')->assertJsonMissingPath('data.payment_status');
        $this->getJson("$url/membership-plans/$plan")->assertOk()->assertJsonMissingPath('data.price_minor');
        $this->getJson("$url/memberships?payment_state=unpaid")->assertForbidden();
        $access->forceFill(['permissions' => ['studio.access', 'payments.view']])->save();
        $this->getJson("$url/finance/members/$m->public_id")->assertOk()->assertJsonPath('data.0.balance_minor', 150000);
        $this->postJson("$url/cash-payments", $body)->assertForbidden();
        $access->forceFill(['permissions' => ['studio.access', 'payments.view', 'payments.record']])->save();
        $p = $this->postJson("$url/cash-payments", $body)->assertCreated()->json('data.id');
        $this->postJson("$url/cash-payments/$p/reverse", ['reason' => 'other'])->assertForbidden();
        $this->getJson("$url/finance/metrics")->assertForbidden();
        $access->forceFill(['permissions' => ['studio.access', 'payments.view', 'payments.correct']])->save();
        $this->postJson("$url/cash-payments/$p/reverse", ['reason' => 'other'])->assertOk();
        $this->postJson("$url/cash-payments", $this->payData($id))->assertForbidden();
    }

    public function test_foreign_ids_and_mass_assignment(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        $p = $this->postJson("$url/cash-payments", $this->payData($id))->assertCreated()->json('data.id');
        [$o2,$u2,$a2,$m2,$plan2,$url2] = $this->financeFixture();
        foreach (["membership-plans/$plan", "memberships/$id", "cash-payments/$p", "finance/members/$m->public_id"] as $path) {
            $this->getJson("$url2/$path")->assertNotFound();
        }
        $this->postJson("$url2/cash-payments", $this->payData($id))->assertNotFound();
        $this->postJson("$url2/cash-payments/$p/reverse", ['reason' => 'other'])->assertNotFound();
        foreach ([[$m->public_id, $plan2], [$m2->public_id, $plan]] as [$mid,$pid]) {
            $this->postJson("$url2/memberships", ['member_id' => $mid, 'plan_id' => $pid, 'starts_on' => '2026-09-01'])->assertNotFound();
        }
        $id2 = $this->assignFinance($url2, $m2, $plan2);
        foreach (['recorded_by', 'organization_id', 'member_id', 'public_id', 'method', 'status', 'created_at', 'currency', 'reversal'] as $field) {
            $this->postJson("$url2/cash-payments", $this->payData($id2) + [$field => 'forged'])->assertUnprocessable();
        }
        foreach ([0, -1, 1.5, '150000', 100000001] as $amount) {
            $this->postJson("$url2/cash-payments", array_replace($this->payData($id2), ['amount_minor' => $amount]))->assertUnprocessable();
        }
        $this->postJson("$url2/cash-payments", array_replace($this->payData($id2), ['paid_at' => '2026-09-25T00:00:00Z']))->assertStatus(409);
        $this->getJson("$url2/cash-payments")->assertJsonCount(0, 'data');
    }

    public function test_validation_inactive_customer_and_explicit_adoption(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        foreach ([['price_minor' => -1], ['price_minor' => 1.5], ['duration_unit' => 'years'], ['duration_count' => 37], ['status' => 'deleted'], ['organization_id' => $o->id]] as $change) {
            $this->postJson("$url/membership-plans", array_replace($this->planData(), $change))->assertUnprocessable();
        }
        $m->forceFill(['status' => 'inactive'])->save();
        $this->postJson("$url/memberships", ['member_id' => $m->public_id, 'plan_id' => $plan, 'starts_on' => '2026-09-01'])->assertStatus(409);
        $a->forceFill(['permissions' => array_values(array_diff($a->permissions, ['memberships.view', 'memberships.manage', 'payments.view', 'payments.record', 'payments.correct']))])->save();
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('gt:finance-access-enable', ['organization' => $o->public_id, 'membership' => $a->public_id, '--reason' => 'Explicit adoption'])->assertSuccessful();
        }
        $this->assertSame(1, DB::table('audit_events')->where('action', 'staff.permissions_changed')->count());
        $this->assertNotContains('payments.view', StaffRole::Trainer->permissions());
    }

    public function test_append_only_ledger_snapshots_and_database_constraints(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        $pid = $this->postJson("$url/cash-payments", $this->payData($id))->assertCreated()->json('data.id');
        $this->postJson("$url/cash-payments/$pid/reverse", ['reason' => 'wrong_member'])->assertOk();
        foreach (['cash_payments', 'payment_reversals', 'member_memberships', 'audit_events'] as $table) {
            foreach (['update', 'delete'] as $operation) {
                try {
                    DB::transaction(function () use ($table, $operation) {
                        $q = DB::table($table);
                        if ($operation === 'delete') {
                            $q->delete();
                        } else {
                            $q->update([$table === 'member_memberships' ? 'agreed_price_minor' : ($table === 'audit_events' ? 'action' : 'amount_minor') => $table === 'audit_events' ? 'forged' : 2]);
                        }
                    });
                    $this->fail('Mutable financial history');
                } catch (QueryException $e) {
                    $this->assertStringStartsWith('23', (string) $e->getCode());
                }
            }
        }
        $cash = CashPayment::firstOrFail()->getAttributes();
        unset($cash['id']);
        foreach ([['amount_minor' => 0], ['amount_minor' => -1], ['currency' => 'EUR'], ['method' => 'card'], ['studio_member_id' => 999999], ['organization_id' => 999999], ['member_membership_id' => 999999], ['paid_at' => '2200-01-01']] as $change) {
            try {
                DB::transaction(fn () => DB::table('cash_payments')->insert(array_replace($cash, ['public_id' => (string) Str::uuid7(), 'request_id' => (string) Str::uuid7()], $change)));
                $this->fail('Invalid payment accepted');
            } catch (QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
        $row = MemberMembership::firstOrFail()->getAttributes();
        unset($row['id']);
        try {
            DB::transaction(fn () => DB::table('member_memberships')->insert(array_replace($row, ['public_id' => (string) Str::uuid7()])));
            $this->fail('Overlap accepted');
        } catch (QueryException $e) {
            $this->assertSame('23P01', (string) $e->getCode());
        }
        $this->patchJson("$url/cash-payments/$pid", ['amount_minor' => 1])->assertStatus(405);
        $this->deleteJson("$url/cash-payments/$pid")->assertStatus(405);
    }

    public function test_authority_is_rechecked_after_lock_and_audit_failure_rolls_back_cash(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        DB::statement("CREATE OR REPLACE FUNCTION gt009_reject_audit() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture'; END $$");
        DB::statement('CREATE TRIGGER gt009_reject_audit BEFORE INSERT ON audit_events FOR EACH ROW EXECUTE FUNCTION gt009_reject_audit()');
        try {
            $this->postJson("$url/cash-payments", $this->payData($id))->assertStatus(500);
            $this->assertDatabaseCount('cash_payments', 0);
        } finally {
            DB::statement('DROP TRIGGER gt009_reject_audit ON audit_events');
            DB::statement('DROP FUNCTION gt009_reject_audit()');
        }
        $revoked = false;
        DB::listen(function ($q) use (&$revoked, $a) {
            if (! $revoked && str_contains($q->sql, '"organizations"') && str_contains($q->sql, 'for update')) {
                $revoked = true;
                DB::table('organization_memberships')->where('id', $a->id)->update(['permissions' => json_encode(array_values(array_diff($a->permissions, ['payments.view'])), JSON_THROW_ON_ERROR)]);
            }
        });
        $this->postJson("$url/cash-payments", $this->payData($id))->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertDatabaseCount('cash_payments', 0);
    }

    public function test_database_rejects_invalid_membership_and_reversal_evidence(): void
    {
        [$o,$u,$a,$m,$plan,$url] = $this->financeFixture();
        $id = $this->assignFinance($url, $m, $plan);
        $pid = $this->postJson("$url/cash-payments", $this->payData($id))->assertCreated()->json('data.id');
        $this->postJson("$url/cash-payments/$pid/reverse", ['reason' => 'other'])->assertOk();
        $membership = MemberMembership::firstOrFail()->getAttributes();
        unset($membership['id']);
        foreach ([['ends_on' => '2026-10-31', 'starts_on' => '2026-11-01'], ['organization_id' => 999999], ['membership_plan_id' => 999999], ['studio_member_id' => 999999], ['currency' => 'EUR'], ['agreed_price_minor' => -1], ['duration_unit' => 'years'], ['cancelled_at' => now(), 'cancelled_by' => $u->id, 'cancellation_reason' => null]] as $change) {
            try {
                DB::transaction(fn () => DB::table('member_memberships')->insert(array_replace($membership, ['public_id' => (string) Str::uuid7(), 'starts_on' => '2027-01-01', 'ends_on' => '2027-01-31'], $change)));
                $this->fail('Invalid membership accepted');
            } catch (QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
        $second = $this->postJson("$url/cash-payments", $this->payData($id))->assertCreated()->json('data.id');
        $secondInternal = CashPayment::where('public_id', $second)->firstOrFail()->id;
        $row = (array) DB::table('payment_reversals')->first();
        unset($row['id']);
        foreach ([[], ['amount_minor' => 149999], ['organization_id' => 999999], ['reason' => 'forged'], ['currency' => 'EUR']] as $change) {
            if ($change !== []) {
                $change['cash_payment_id'] = $secondInternal;
            }
            try {
                DB::transaction(fn () => DB::table('payment_reversals')->insert(array_replace($row, ['public_id' => (string) Str::uuid7()], $change)));
                $this->fail('Invalid reversal accepted');
            } catch (QueryException $e) {
                $this->assertStringStartsWith('23', (string) $e->getCode());
            }
        }
    }
}
