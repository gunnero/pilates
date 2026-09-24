<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Support\Notifications\Delivery;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NotificationFixtures;
use Tests\TestCase;

final class CommunicationMigrationTest extends TestCase
{
    use DatabaseMigrations, NotificationFixtures;

    public function test_populated_gt014_upgrade_preserves_every_existing_column_and_grant(): void
    {
        [$o, $owner, , , $user] = $this->notificationFixture();
        $base = $this->base($o);
        $this->actingAs($owner)->patchJson($base.'/settings', ['reminder_lead_hours' => 12])->assertOk();
        $this->actingAs($user)->patchJson($base.'/notifications/preferences', ['reminders_enabled' => false])->assertOk();
        Delivery::run(NotificationDelivery::firstOrFail()->id);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
        $tables = ['users', 'organizations', 'locations', 'organization_memberships', 'membership_locations', 'studio_members', 'trainer_profiles', 'training_groups', 'schedule_rules', 'training_sessions', 'reservations', 'waiting_list_entries', 'permanent_spots', 'permanent_spot_releases', 'attendance_records', 'audit_events', 'membership_plans', 'member_memberships', 'cash_payments', 'payment_reversals', 'platform_notifications', 'notification_deliveries', 'notification_delivery_attempts', 'notification_reads', 'notification_preferences', 'member_invitations', 'account_security_events', 'personal_access_tokens', 'studio_settings'];
        $columns = [];
        $snapshot = function ($table) use (&$columns) {
            $columns[$table] ??= Schema::getColumnListing($table);

            return DB::select('SELECT row_to_json(t)::text AS value FROM (SELECT '.implode(',', $columns[$table]).' FROM '.$table.') t ORDER BY row_to_json(t)::text');
        };
        $before = array_combine($tables, array_map($snapshot, $tables));
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], $snapshot($table), $table);
        }
        $this->assertDatabaseHas('notification_preferences', ['reminders_enabled' => false, 'push_enabled' => true, 'email_enabled' => false]);
        $this->assertDatabaseHas('studio_settings', ['reminder_lead_hours' => 12]);
        $this->assertDatabaseCount('device_push_tokens', 0);
        $this->assertDatabaseCount('studio_announcements', 0);
    }
}
