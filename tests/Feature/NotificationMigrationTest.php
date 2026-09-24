<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceFixtures;
use Tests\TestCase;

class NotificationMigrationTest extends TestCase
{
    use DatabaseMigrations,FinanceFixtures;

    public function test_populated_gt009_upgrade_preserves_existing_records_and_grants(): void
    {
        [$o,$u,$a,$m,$plan,$base] = $this->financeFixture();
        $id = $this->assignFinance($base, $m, $plan);
        $payment = $this->postJson("$base/cash-payments", $this->payData($id))->assertCreated()->json('data.id');
        $this->postJson("$base/cash-payments/$payment/reverse", ['reason' => 'wrong_amount'])->assertOk();
        Artisan::call('migrate:rollback', ['--step' => 4, '--force' => true]);
        $tables = ['users', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'training_sessions', 'reservations', 'waiting_list_entries', 'permanent_spots', 'permanent_spot_releases', 'attendance_records', 'audit_events', 'membership_plans', 'member_memberships', 'cash_payments', 'payment_reversals'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::select('SELECT row_to_json(t)::text AS value FROM '.$table.' t ORDER BY row_to_json(t)::text');
        }
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], DB::select('SELECT row_to_json(t)::text AS value FROM '.$table.' t ORDER BY row_to_json(t)::text'), $table);
        }
        $this->assertDatabaseCount('cash_payments', 1);
        $this->assertDatabaseCount('payment_reversals', 1);
        $this->assertDatabaseCount('platform_notifications', 0);
    }
}
