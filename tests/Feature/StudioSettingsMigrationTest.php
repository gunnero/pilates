<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Models\StudioMember;
use App\Models\TrainingSession;
use App\Support\Notifications\Delivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinanceFixtures;
use Tests\Support\SessionFixtures;
use Tests\TestCase;

class StudioSettingsMigrationTest extends TestCase
{
    use DatabaseMigrations, FinanceFixtures, SessionFixtures;

    public function test_populated_upgrade_and_settings_updates_preserve_gt013_business_evidence(): void
    {
        [$o, , , $l, $t, $g] = $this->sessionFixture();
        $base = $this->base($o);
        $m = new StudioMember;
        $m->forceFill(['organization_id' => $o->id, 'first_name' => 'Preserved', 'last_name' => 'Fixture', 'status' => 'active', 'preferred_locale' => 'mk', 'digital_access_mode' => 'offline'])->save();
        $sid = $this->postJson($base.'/sessions', $this->sessionData($g, $l, $t))->assertCreated()->json('data.id');
        $s = TrainingSession::where('public_id', $sid)->firstOrFail();
        $this->postJson("$base/sessions/$sid/reservations", ['member_id' => $m->public_id])->assertOk();
        $this->travelTo($s->starts_at->subMinutes(10));
        $this->postJson("$base/sessions/$sid/attendance", ['member_id' => $m->public_id, 'status' => 'present'])->assertOk();
        $plan = $this->postJson($base.'/membership-plans', $this->planData())->assertCreated()->json('data.id');
        $id = $this->assignFinance($base, $m, $plan);
        $data = $this->payData($id);
        $data['paid_at'] = now()->toIso8601String();
        $payment = $this->postJson($base.'/cash-payments', $data)->assertCreated()->json('data.id');
        $this->postJson("$base/cash-payments/$payment/reverse", ['reason' => 'wrong_amount'])->assertOk();
        Delivery::run(NotificationDelivery::firstOrFail()->id);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $tables = ['users', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'training_sessions', 'reservations', 'waiting_list_entries', 'permanent_spots', 'permanent_spot_releases', 'attendance_records', 'audit_events', 'membership_plans', 'member_memberships', 'cash_payments', 'payment_reversals', 'platform_notifications', 'notification_deliveries', 'notification_delivery_attempts', 'notification_reads', 'notification_preferences', 'member_invitations', 'account_security_events'];
        $snapshot = fn ($table) => DB::select('SELECT row_to_json(t)::text AS value FROM '.$table.' t ORDER BY row_to_json(t)::text');
        $before = array_combine($tables, array_map($snapshot, $tables));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], $snapshot($table), $table);
        }
        $this->patchJson($base.'/settings', ['self_check_in_enabled' => false, 'self_reservation_enabled' => false, 'session_reminders_enabled' => false, 'membership_expiring_days' => 30])->assertOk();
        foreach (array_diff($tables, ['audit_events']) as $table) {
            $this->assertEquals($before[$table], $snapshot($table), $table);
        }
        foreach (['training_sessions', 'reservations', 'attendance_records', 'member_memberships', 'cash_payments', 'payment_reversals'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
    }
}
