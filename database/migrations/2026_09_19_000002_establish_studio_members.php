<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_PERMISSIONS = '["studio.access","organizations.view","organizations.manage","locations.view","locations.manage","staff.view","staff.manage","permissions.view","permissions.manage","attendance.view","payments.view","payments.record","payments.correct","reports.view"]';

    private const MEMBER_PERMISSIONS = '["members.view","members.create","members.update","members.status.manage","members.notes.view","members.notes.manage"]';

    public function up(): void
    {
        // Widen the catalog, preserving every existing explicit grant unchanged.
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ ('".self::LEGACY_PERMISSIONS."'::jsonb || '".self::MEMBER_PERMISSIONS."'::jsonb))");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member'))");
        Schema::create('studio_members', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('phone', 16)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('preferred_locale', 2)->default('mk');
            $table->string('digital_access_mode', 16)->default('offline');
            $table->string('notes', 2000)->nullable();
            $table->unsignedBigInteger('preferred_location_id')->nullable();
            $table->foreign(['organization_id', 'preferred_location_id'])->references(['organization_id', 'id'])->on('locations')->restrictOnDelete();
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->unique(['organization_id', 'user_id']);
            $table->index(['organization_id', 'status', 'id']);
            $table->index(['organization_id', 'digital_access_mode', 'id']);
            $table->index(['organization_id', 'preferred_location_id']);
        });
        DB::statement("ALTER TABLE studio_members ADD CONSTRAINT member_status_check CHECK (status IN ('active','inactive','archived')), ADD CONSTRAINT member_mode_check CHECK (digital_access_mode IN ('app','assisted','offline')), ADD CONSTRAINT member_locale_check CHECK (preferred_locale IN ('mk','en')), ADD CONSTRAINT member_names_check CHECK (length(btrim(first_name)) > 0 AND length(btrim(last_name)) > 0), ADD CONSTRAINT member_email_check CHECK (email IS NULL OR (email = lower(btrim(email)) AND email ~ '^[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+$')), ADD CONSTRAINT member_phone_check CHECK (phone IS NULL OR phone ~ '^\+[1-9][0-9]{6,14}$')");
        DB::statement('CREATE INDEX member_first_search ON studio_members (organization_id, lower(first_name) text_pattern_ops)');
        DB::statement('CREATE INDEX member_last_search ON studio_members (organization_id, lower(last_name) text_pattern_ops)');
        DB::statement("CREATE INDEX member_full_search ON studio_members (organization_id, lower(first_name || ' ' || last_name) text_pattern_ops)");
        DB::statement('CREATE INDEX member_email_search ON studio_members (organization_id, email varchar_pattern_ops)');
        DB::statement('CREATE INDEX member_phone_search ON studio_members (organization_id, phone varchar_pattern_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_members');
        // Preserve historical audit rows and explicit grants on operator rollback.
        // NOT VALID checks apply to future writes; do not erase evidence to downgrade.
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership')) NOT VALID");
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ '".self::LEGACY_PERMISSIONS."'::jsonb) NOT VALID");
    }
};
