<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->unique()->constrained('organizations')->restrictOnDelete();
            $table->smallInteger('reservation_horizon_days')->nullable();
            $table->smallInteger('reservation_cutoff_minutes')->nullable();
            $table->smallInteger('cancellation_cutoff_minutes')->nullable();
            $table->boolean('waiting_list_enabled')->nullable();
            $table->boolean('self_reservation_enabled')->nullable();
            $table->boolean('self_cancellation_enabled')->nullable();
            $table->boolean('self_check_in_enabled')->nullable();
            $table->smallInteger('check_in_early_minutes')->nullable();
            $table->smallInteger('check_in_late_minutes')->nullable();
            $table->smallInteger('qr_lifetime_seconds')->nullable();
            $table->smallInteger('qr_rotation_seconds')->nullable();
            $table->boolean('session_reminders_enabled')->nullable();
            $table->smallInteger('reminder_lead_hours')->nullable();
            $table->boolean('expiry_warnings_enabled')->nullable();
            $table->smallInteger('expiry_warning_days')->nullable();
            $table->smallInteger('membership_expiring_days')->nullable();
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_reservation_horizon_days_bounds CHECK (reservation_horizon_days BETWEEN 0 AND 56)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_reservation_cutoff_minutes_bounds CHECK (reservation_cutoff_minutes BETWEEN 0 AND 1440)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_cancellation_cutoff_minutes_bounds CHECK (cancellation_cutoff_minutes BETWEEN 0 AND 1440)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_check_in_early_minutes_bounds CHECK (check_in_early_minutes BETWEEN 0 AND 120)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_check_in_late_minutes_bounds CHECK (check_in_late_minutes BETWEEN 0 AND 120)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_qr_lifetime_seconds_bounds CHECK (qr_lifetime_seconds BETWEEN 30 AND 90)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_qr_rotation_seconds_bounds CHECK (qr_rotation_seconds BETWEEN 10 AND 30)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_reminder_lead_hours_bounds CHECK (reminder_lead_hours BETWEEN 1 AND 72)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_expiry_warning_days_bounds CHECK (expiry_warning_days BETWEEN 1 AND 30)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_membership_expiring_days_bounds CHECK (membership_expiring_days BETWEEN 1 AND 30)');
        DB::statement('ALTER TABLE studio_settings ADD CONSTRAINT settings_qr_overlap CHECK (2 * COALESCE(qr_rotation_seconds, 30) <= COALESCE(qr_lifetime_seconds, 90))');
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_settings');
    }
};
