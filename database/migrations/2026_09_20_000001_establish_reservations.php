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
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions)='array' AND permissions <@ '[\"reservations.view\", \"reservations.manage\", \"reservations.cancel\", \"waiting_list.view\", \"waiting_list.manage\", \"permanent_spots.view\", \"permanent_spots.manage\", \"studio.access\", \"organizations.view\", \"organizations.manage\", \"locations.view\", \"locations.manage\", \"staff.view\", \"staff.manage\", \"permissions.view\", \"permissions.manage\", \"sessions.view\", \"sessions.manage\", \"sessions.cancel\", \"groups.view\", \"groups.manage\", \"schedule.view\", \"schedule.manage\", \"trainers.view\", \"trainers.manage\", \"members.view\", \"members.create\", \"members.update\", \"members.status.manage\", \"members.notes.view\", \"members.notes.manage\", \"attendance.view\", \"payments.view\", \"payments.record\", \"payments.correct\", \"reports.view\"]'::jsonb)");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot'))");

        Schema::table('training_sessions', fn (Blueprint $t) => $t->unique(['organization_id', 'id']));
        Schema::create('permanent_spots', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('training_group_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->string('status', 16)->default('active');
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['organization_id', 'id']);
            $t->unique(['organization_id', 'studio_member_id', 'id'], 'spot_member_identity');
            $t->foreign(['organization_id', 'training_group_id'])->references(['organization_id', 'id'])->on('training_groups')->restrictOnDelete();
            $t->foreign(['organization_id', 'studio_member_id'])->references(['organization_id', 'id'])->on('studio_members')->restrictOnDelete();
            $t->index(['organization_id', 'training_group_id', 'status', 'effective_from'], 'spot_group_period');
            $t->index(['organization_id', 'studio_member_id']);
        });
        DB::statement("ALTER TABLE permanent_spots ADD CONSTRAINT spot_status CHECK (status IN ('active','inactive')), ADD CONSTRAINT spot_period CHECK (effective_from BETWEEN DATE '2000-01-01' AND DATE '2100-12-31' AND (effective_until IS NULL OR effective_until BETWEEN effective_from AND DATE '2100-12-31')), ADD CONSTRAINT spot_no_overlap EXCLUDE USING gist (int8range(training_group_id,training_group_id,'[]') WITH &&, int8range(studio_member_id,studio_member_id,'[]') WITH &&, daterange(effective_from,effective_until,'[]') WITH &&) WHERE (status='active')");
        Schema::create('reservations', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('training_session_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->unsignedBigInteger('permanent_spot_id')->nullable();
            $t->string('status', 16)->default('active');
            $t->string('source', 20);
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['organization_id', 'training_session_id', 'studio_member_id', 'id'], 'reservation_identity');
            $t->foreign(['organization_id', 'training_session_id'])->references(['organization_id', 'id'])->on('training_sessions')->restrictOnDelete();
            $t->foreign(['organization_id', 'studio_member_id'])->references(['organization_id', 'id'])->on('studio_members')->restrictOnDelete();
            $t->foreign(['organization_id', 'studio_member_id', 'permanent_spot_id'], 'reservation_spot_fk')->references(['organization_id', 'studio_member_id', 'id'])->on('permanent_spots')->restrictOnDelete();
            $t->index(['organization_id', 'training_session_id', 'status'], 'reservation_session_status');
            $t->index(['organization_id', 'studio_member_id', 'status'], 'reservation_member_status');
            $t->index(['permanent_spot_id', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX reservation_one_active ON reservations (training_session_id,studio_member_id) WHERE status='active'");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservation_status CHECK ((status='active' AND cancelled_at IS NULL) OR (status='cancelled' AND cancelled_at IS NOT NULL)), ADD CONSTRAINT reservation_source CHECK ((source='permanent_spot' AND permanent_spot_id IS NOT NULL) OR (source IN ('staff','member','waiting_list') AND permanent_spot_id IS NULL))");
        Schema::create('waiting_list_entries', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('training_session_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->unsignedBigInteger('reservation_id')->nullable();
            $t->string('status', 16)->default('waiting');
            $t->string('source', 16);
            $t->timestampTz('joined_at');
            $t->timestampTz('promoted_at')->nullable();
            $t->timestampTz('cancelled_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->foreign(['organization_id', 'training_session_id'])->references(['organization_id', 'id'])->on('training_sessions')->restrictOnDelete();
            $t->foreign(['organization_id', 'studio_member_id'])->references(['organization_id', 'id'])->on('studio_members')->restrictOnDelete();
            $t->foreign(['organization_id', 'training_session_id', 'studio_member_id', 'reservation_id'], 'waiting_reservation_fk')->references(['organization_id', 'training_session_id', 'studio_member_id', 'id'])->on('reservations')->restrictOnDelete();
            $t->index(['training_session_id', 'status', 'joined_at', 'id'], 'waiting_fifo');
            $t->index(['organization_id', 'studio_member_id', 'status'], 'waiting_member_status');
        });
        DB::statement("CREATE UNIQUE INDEX waiting_one_active ON waiting_list_entries (training_session_id,studio_member_id) WHERE status='waiting'");
        DB::statement("ALTER TABLE waiting_list_entries ADD CONSTRAINT waiting_source CHECK (source IN ('staff','member')), ADD CONSTRAINT waiting_status CHECK ((status='waiting' AND promoted_at IS NULL AND cancelled_at IS NULL AND reservation_id IS NULL) OR (status='promoted' AND promoted_at IS NOT NULL AND cancelled_at IS NULL AND reservation_id IS NOT NULL) OR (status IN ('cancelled','ineligible') AND cancelled_at IS NOT NULL AND promoted_at IS NULL AND reservation_id IS NULL))");
        // A release survives retries, assignment changes and manual-source collisions.
        Schema::create('permanent_spot_releases', function (Blueprint $t): void {
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('training_session_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->timestampTz('released_at');
            $t->primary(['training_session_id', 'studio_member_id']);
            $t->foreign(['organization_id', 'training_session_id'])->references(['organization_id', 'id'])->on('training_sessions')->restrictOnDelete();
            $t->foreign(['organization_id', 'studio_member_id'])->references(['organization_id', 'id'])->on('studio_members')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions)='array' AND permissions <@ '[\"studio.access\", \"organizations.view\", \"organizations.manage\", \"locations.view\", \"locations.manage\", \"staff.view\", \"staff.manage\", \"permissions.view\", \"permissions.manage\", \"sessions.view\", \"sessions.manage\", \"sessions.cancel\", \"groups.view\", \"groups.manage\", \"schedule.view\", \"schedule.manage\", \"trainers.view\", \"trainers.manage\", \"members.view\", \"members.create\", \"members.update\", \"members.status.manage\", \"members.notes.view\", \"members.notes.manage\", \"attendance.view\", \"payments.view\", \"payments.record\", \"payments.correct\", \"reports.view\"]'::jsonb) NOT VALID");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session')) NOT VALID");

        Schema::dropIfExists('permanent_spot_releases');
        Schema::dropIfExists('waiting_list_entries');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('permanent_spots');
        Schema::table('training_sessions', fn (Blueprint $t) => $t->dropUnique(['organization_id', 'id']));
    }
};
