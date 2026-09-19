<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = '["studio.access","organizations.view","organizations.manage","locations.view","locations.manage","staff.view","staff.manage","permissions.view","permissions.manage","attendance.view","payments.view","payments.record","payments.correct","reports.view","members.view","members.create","members.update","members.status.manage","members.notes.view","members.notes.manage","trainers.view","trainers.manage"]';

    public function up(): void
    {
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ ('".self::LEGACY."'::jsonb || '[\"groups.view\",\"groups.manage\",\"schedule.view\",\"schedule.manage\"]'::jsonb))");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule'))");
        Schema::create('training_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('primary_trainer_id')->nullable();
            $table->foreign(['organization_id', 'location_id'])->references(['organization_id', 'id'])->on('locations')->restrictOnDelete();
            $table->foreign(['organization_id', 'primary_trainer_id'])->references(['organization_id', 'id'])->on('trainer_profiles')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('description', 2000)->nullable();
            $table->unsignedSmallInteger('capacity');
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'location_id', 'status', 'id']);
            $table->index(['organization_id', 'primary_trainer_id']);
        });
        DB::statement("ALTER TABLE training_groups ADD CONSTRAINT group_capacity_check CHECK (capacity BETWEEN 1 AND 100), ADD CONSTRAINT group_status_check CHECK (status IN ('active','inactive','archived')), ADD CONSTRAINT group_name_check CHECK (length(btrim(name)) > 0)");
        Schema::create('schedule_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('training_group_id');
            $table->foreign(['organization_id', 'training_group_id'])->references(['organization_id', 'id'])->on('training_groups')->restrictOnDelete();
            $table->unsignedSmallInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->unsignedBigInteger('replaces_rule_id')->nullable()->unique();
            $table->unique(['organization_id', 'training_group_id', 'id'], 'rule_ownership_unique');
            $table->foreign(['organization_id', 'training_group_id', 'replaces_rule_id'], 'rule_predecessor_fk')->references(['organization_id', 'training_group_id', 'id'])->on('schedule_rules')->restrictOnDelete();
            $table->timestampsTz();
            $table->index(['organization_id', 'training_group_id', 'weekday', 'start_minute', 'effective_from'], 'rule_directory_index');
        });
        DB::statement("ALTER TABLE schedule_rules ADD CONSTRAINT rule_weekday_check CHECK (weekday BETWEEN 1 AND 7), ADD CONSTRAINT rule_time_check CHECK (start_minute >= 0 AND end_minute <= 1439 AND end_minute > start_minute AND end_minute - start_minute <= 240), ADD CONSTRAINT rule_dates_check CHECK (effective_from BETWEEN DATE '2000-01-01' AND DATE '2100-12-31' AND (effective_until IS NULL OR (effective_until >= effective_from AND effective_until <= DATE '2100-12-31')))");
        // Native range operators avoid a privileged btree_gist extension installation.
        DB::statement("ALTER TABLE schedule_rules ADD CONSTRAINT rule_no_group_overlap EXCLUDE USING gist (int8range(training_group_id, training_group_id, '[]') WITH &&, int4range(weekday * 1440 + start_minute, weekday * 1440 + end_minute, '[)') WITH &&, daterange(effective_from, effective_until, '[]') WITH &&) WHERE (canceled_at IS NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_rules');
        Schema::dropIfExists('training_groups');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile')) NOT VALID");
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ '".self::LEGACY."'::jsonb) NOT VALID");
    }
};
