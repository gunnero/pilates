<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = '["studio.access","organizations.view","organizations.manage","locations.view","locations.manage","staff.view","staff.manage","permissions.view","permissions.manage","attendance.view","payments.view","payments.record","payments.correct","reports.view","members.view","members.create","members.update","members.status.manage","members.notes.view","members.notes.manage"]';

    public function up(): void
    {
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ ('".self::LEGACY."'::jsonb || '[\"trainers.view\",\"trainers.manage\"]'::jsonb))");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile'))");
        Schema::create('trainer_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('organization_membership_id')->unique();
            $table->foreign(['organization_id', 'organization_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships')->restrictOnDelete();
            $table->string('display_name', 160)->nullable();
            $table->string('bio', 1000)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status', 'id']);
        });
        DB::statement("ALTER TABLE trainer_profiles ADD CONSTRAINT trainer_status_check CHECK (status IN ('active','inactive')), ADD CONSTRAINT trainer_display_name_check CHECK (display_name IS NULL OR length(btrim(display_name)) > 0)");
        DB::statement('CREATE INDEX trainer_display_search ON trainer_profiles (organization_id, lower(display_name) text_pattern_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('trainer_profiles');
        // Operator rollback preserves existing evidence and explicit grants, as in GT-003.
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member')) NOT VALID");
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ '".self::LEGACY."'::jsonb) NOT VALID");
    }
};
