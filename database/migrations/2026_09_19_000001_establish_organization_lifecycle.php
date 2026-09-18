<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('slug', 100)->nullable();
            $table->string('status', 16)->default('active');
        });
        DB::statement("UPDATE organizations SET slug = 'studio-' || public_id::text");
        DB::statement('ALTER TABLE organizations ALTER COLUMN slug SET NOT NULL');
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_slug_unique UNIQUE(slug), ADD CONSTRAINT organizations_slug_format CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'), ADD CONSTRAINT organizations_status_check CHECK (status IN ('active','inactive','suspended')), ADD CONSTRAINT organizations_locale_check CHECK (locale IN ('mk','en')), ADD CONSTRAINT organizations_currency_check CHECK (currency = 'MKD')");
        // PostgreSQL's timezone catalog is authoritative for the database constraint.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION gt_validate_timezone() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = NEW.timezone) THEN
  RAISE EXCEPTION 'Invalid organization timezone' USING ERRCODE = '23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER organizations_timezone_check BEFORE INSERT OR UPDATE ON organizations FOR EACH ROW EXECUTE FUNCTION gt_validate_timezone();
SQL);
        // Validate pre-existing GT-001 rows as well as future writes.
        DB::statement('UPDATE organizations SET timezone = timezone');
        Schema::rename('organization_user', 'organization_memberships');
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->uuid('public_id')->default(DB::raw('uuidv7()'))->unique();
            $table->string('role', 24)->default('staff');
            $table->string('status', 16)->default('active');
            $table->string('location_scope', 16)->default('all');
            $table->unique(['organization_id', 'id']);
        });
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_role_check CHECK (role IN ('owner','administrator','trainer','staff')), ADD CONSTRAINT membership_status_check CHECK (status IN ('active','suspended','revoked')), ADD CONSTRAINT membership_scope_check CHECK (location_scope IN ('all','selected')), ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions) = 'array' AND permissions <@ '[\"studio.access\",\"organizations.view\",\"organizations.manage\",\"locations.view\",\"locations.manage\",\"staff.view\",\"staff.manage\",\"permissions.view\",\"permissions.manage\",\"attendance.view\",\"payments.view\",\"payments.record\",\"payments.correct\",\"reports.view\"]'::jsonb), ADD CONSTRAINT owner_access_check CHECK (role <> 'owner' OR (location_scope = 'all' AND permissions @> '[\"studio.access\",\"organizations.view\",\"organizations.manage\",\"staff.view\",\"staff.manage\",\"permissions.view\",\"permissions.manage\"]'::jsonb))");
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('address', 300)->nullable();
            $table->string('city', 100)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
            $table->unique(['organization_id', 'id']);
            $table->index(['organization_id', 'status']);
        });
        DB::statement("ALTER TABLE locations ADD CONSTRAINT locations_status_check CHECK (status IN ('active','inactive')), ADD CONSTRAINT locations_country_check CHECK (country_code IS NULL OR country_code ~ '^[A-Z]{2}$')");
        Schema::create('membership_locations', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('organization_membership_id');
            $table->unsignedBigInteger('location_id');
            $table->primary(['organization_membership_id', 'location_id']);
            $table->foreign(['organization_id', 'organization_membership_id'])->references(['organization_id', 'id'])->on('organization_memberships')->restrictOnDelete();
            $table->foreign(['organization_id', 'location_id'])->references(['organization_id', 'id'])->on('locations')->restrictOnDelete();
            $table->index(['organization_id', 'location_id']);
        });
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 40);
            $table->uuid('subject_id');
            $table->timestampTz('occurred_at');
            $table->jsonb('metadata');
            $table->index(['organization_id', 'occurred_at']);
        });
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_metadata_check CHECK (jsonb_typeof(metadata) = 'object' AND octet_length(metadata::text) <= 8192), ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION gt_audit_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Audit evidence is append-only' USING ERRCODE = '23514'; END $$;
CREATE TRIGGER audit_no_mutation BEFORE UPDATE OR DELETE ON audit_events FOR EACH ROW EXECUTE FUNCTION gt_audit_append_only();
CREATE TRIGGER audit_no_truncate BEFORE TRUNCATE ON audit_events FOR EACH STATEMENT EXECUTE FUNCTION gt_audit_append_only();
SQL);
    }

    public function down(): void
    {
        // Explicit schema rollback is an operator action, not an application audit deletion path.
        Schema::dropIfExists('audit_events');
        DB::statement('DROP FUNCTION IF EXISTS gt_audit_append_only()');
        Schema::dropIfExists('membership_locations');
        Schema::dropIfExists('locations');
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT owner_access_check, DROP CONSTRAINT membership_permissions_check, DROP CONSTRAINT membership_scope_check, DROP CONSTRAINT membership_status_check, DROP CONSTRAINT membership_role_check');
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropUnique(['organization_id', 'id']);
            $table->dropColumn(['public_id', 'role', 'status', 'location_scope']);
        });
        Schema::rename('organization_memberships', 'organization_user');
        DB::statement('DROP TRIGGER organizations_timezone_check ON organizations');
        DB::statement('DROP FUNCTION gt_validate_timezone()');
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT organizations_locale_check, DROP CONSTRAINT organizations_currency_check');
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['slug', 'status']));
    }
};
