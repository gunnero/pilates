<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions)='array' AND permissions <@ '[\"reservations.view\", \"reservations.manage\", \"reservations.cancel\", \"waiting_list.view\", \"waiting_list.manage\", \"permanent_spots.view\", \"permanent_spots.manage\", \"studio.access\", \"organizations.view\", \"organizations.manage\", \"locations.view\", \"locations.manage\", \"staff.view\", \"staff.manage\", \"permissions.view\", \"permissions.manage\", \"sessions.view\", \"sessions.manage\", \"sessions.cancel\", \"groups.view\", \"groups.manage\", \"schedule.view\", \"schedule.manage\", \"trainers.view\", \"trainers.manage\", \"members.view\", \"members.create\", \"members.update\", \"members.status.manage\", \"members.notes.view\", \"members.notes.manage\", \"attendance.view\", \"attendance.manage\", \"attendance.correct\", \"payments.view\", \"payments.record\", \"payments.correct\", \"reports.view\"]'::jsonb)");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record'))");

        Schema::create('attendance_records', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('training_session_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->unsignedBigInteger('reservation_id');
            $t->string('status', 16);
            $t->string('check_in_method', 16);
            $t->timestampTz('checked_in_at')->nullable();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['training_session_id', 'studio_member_id'], 'attendance_one_member_session');
            $t->foreign(['organization_id', 'training_session_id', 'studio_member_id', 'reservation_id'], 'attendance_reservation_fk')->references(['organization_id', 'training_session_id', 'studio_member_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->index(['organization_id', 'training_session_id', 'status'], 'attendance_session_status');
            $t->index(['organization_id', 'studio_member_id', 'status', 'created_at'], 'attendance_member_history');
        });
        DB::statement("ALTER TABLE attendance_records ADD CONSTRAINT attendance_status CHECK (status IN ('present','absent','no_show')), ADD CONSTRAINT attendance_method CHECK (check_in_method IN ('trainer','staff','member','qr')), ADD CONSTRAINT attendance_time CHECK ((status='present' AND checked_in_at IS NOT NULL AND checked_in_at <= updated_at) OR (status IN ('absent','no_show') AND checked_in_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions)='array' AND permissions <@ '[\"reservations.view\", \"reservations.manage\", \"reservations.cancel\", \"waiting_list.view\", \"waiting_list.manage\", \"permanent_spots.view\", \"permanent_spots.manage\", \"studio.access\", \"organizations.view\", \"organizations.manage\", \"locations.view\", \"locations.manage\", \"staff.view\", \"staff.manage\", \"permissions.view\", \"permissions.manage\", \"sessions.view\", \"sessions.manage\", \"sessions.cancel\", \"groups.view\", \"groups.manage\", \"schedule.view\", \"schedule.manage\", \"trainers.view\", \"trainers.manage\", \"members.view\", \"members.create\", \"members.update\", \"members.status.manage\", \"members.notes.view\", \"members.notes.manage\", \"attendance.view\", \"payments.view\", \"payments.record\", \"payments.correct\", \"reports.view\"]'::jsonb) NOT VALID");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot')) NOT VALID");

    }
};
