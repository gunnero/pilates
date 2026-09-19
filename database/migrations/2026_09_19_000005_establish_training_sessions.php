<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = '["studio.access","organizations.view","organizations.manage","locations.view","locations.manage","staff.view","staff.manage","permissions.view","permissions.manage","attendance.view","payments.view","payments.record","payments.correct","reports.view","members.view","members.create","members.update","members.status.manage","members.notes.view","members.notes.manage","trainers.view","trainers.manage","groups.view","groups.manage","schedule.view","schedule.manage"]';

    public function up(): void
    {
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ ('".self::LEGACY."'::jsonb || '[\"sessions.view\",\"sessions.manage\",\"sessions.cancel\"]'::jsonb))");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session'))");
        Schema::create('training_sessions', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('training_group_id');
            $t->unsignedBigInteger('schedule_rule_id')->nullable();
            $t->unsignedBigInteger('location_id');
            $t->unsignedBigInteger('trainer_profile_id');
            $t->foreign(['organization_id', 'training_group_id'])->references(['organization_id', 'id'])->on('training_groups')->restrictOnDelete();
            $t->foreign(['organization_id', 'training_group_id', 'schedule_rule_id'], 'session_rule_fk')->references(['organization_id', 'training_group_id', 'id'])->on('schedule_rules')->restrictOnDelete();
            $t->foreign(['organization_id', 'location_id'])->references(['organization_id', 'id'])->on('locations')->restrictOnDelete();
            $t->foreign(['organization_id', 'trainer_profile_id'])->references(['organization_id', 'id'])->on('trainer_profiles')->restrictOnDelete();
            $t->string('source_type', 16);
            $t->date('occurrence_date')->nullable();
            $t->date('local_date');
            $t->timestampTz('starts_at');
            $t->timestampTz('ends_at');
            $t->string('timezone', 64);
            $t->string('group_name', 160);
            $t->string('location_name', 160);
            $t->string('trainer_name', 255);
            $t->unsignedSmallInteger('capacity');
            $t->string('status', 16)->default('scheduled');
            $t->boolean('is_overridden')->default(false);
            $t->timestampTz('cancelled_at')->nullable();
            $t->string('cancellation_kind', 16)->nullable();
            $t->string('cancellation_reason', 300)->nullable();
            $t->timestampsTz();
            $t->unique(['schedule_rule_id', 'occurrence_date'], 'session_occurrence_unique');
            $t->index(['organization_id', 'local_date', 'starts_at', 'training_group_id'], 'session_calendar_index');
            $t->index(['organization_id', 'location_id', 'local_date']);
            $t->index(['organization_id', 'starts_at']);
            $t->index(['organization_id', 'location_id', 'starts_at']);
            $t->index(['organization_id', 'trainer_profile_id', 'starts_at']);
            $t->index(['organization_id', 'training_group_id', 'starts_at']);
        });
        DB::statement("ALTER TABLE training_sessions ADD CONSTRAINT session_source_check CHECK ((source_type='recurring' AND schedule_rule_id IS NOT NULL AND occurrence_date IS NOT NULL) OR (source_type='manual' AND schedule_rule_id IS NULL AND occurrence_date IS NULL)), ADD CONSTRAINT session_capacity_check CHECK (capacity BETWEEN 1 AND 100), ADD CONSTRAINT session_time_check CHECK (starts_at < ends_at AND ends_at - starts_at <= INTERVAL '5 hours'), ADD CONSTRAINT session_status_check CHECK ((status='scheduled' AND cancelled_at IS NULL AND cancellation_kind IS NULL AND cancellation_reason IS NULL) OR (status='cancelled' AND cancelled_at IS NOT NULL AND cancellation_kind IS NOT NULL AND cancellation_kind IN ('manual','schedule'))), ADD CONSTRAINT session_date_check CHECK (local_date BETWEEN DATE '2000-01-01' AND DATE '2100-12-31' AND (occurrence_date IS NULL OR occurrence_date BETWEEN DATE '2000-01-01' AND DATE '2100-12-31'))");
        DB::statement("ALTER TABLE training_sessions ADD CONSTRAINT session_local_time_check CHECK ((starts_at AT TIME ZONE timezone)::date = local_date AND (ends_at AT TIME ZONE timezone)::date = local_date AND (ends_at AT TIME ZONE timezone) > (starts_at AT TIME ZONE timezone) AND (ends_at AT TIME ZONE timezone) - (starts_at AT TIME ZONE timezone) <= INTERVAL '4 hours')");
        DB::statement("ALTER TABLE training_sessions ADD CONSTRAINT session_no_trainer_overlap EXCLUDE USING gist (int8range(trainer_profile_id,trainer_profile_id,'[]') WITH &&, tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE (status='scheduled')");
        DB::statement("ALTER TABLE training_sessions ADD CONSTRAINT session_no_group_overlap EXCLUDE USING gist (int8range(training_group_id,training_group_id,'[]') WITH &&, tstzrange(starts_at,ends_at,'[)') WITH &&) WHERE (status='scheduled')");
    }

    public function down(): void
    {
        Schema::dropIfExists('training_sessions');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule')) NOT VALID");
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ '".self::LEGACY."'::jsonb) NOT VALID");
    }
};
