<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX member_account_identity ON studio_members(organization_id,id,user_id)');
        Schema::create('member_invitations', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('studio_member_id');
            $t->foreign(['organization_id', 'studio_member_id'])->references(['organization_id', 'id'])->on('studio_members')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->string('email', 254);
            $t->string('token_hash', 64)->unique();
            $t->string('status', 16)->default('pending');
            $t->timestampTz('expires_at');
            $t->timestampTz('consumed_at')->nullable();
            $t->timestampTz('revoked_at')->nullable();
            $t->unsignedBigInteger('accepted_user_id')->nullable();
            $t->timestampsTz();
            $t->foreign(['organization_id', 'studio_member_id', 'accepted_user_id'])->references(['organization_id', 'id', 'user_id'])->on('studio_members')->restrictOnDelete();
            $t->index(['organization_id', 'studio_member_id', 'id']);
        });
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX member_invitation_pending ON member_invitations(organization_id,studio_member_id) WHERE status='pending';
ALTER TABLE member_invitations ADD CONSTRAINT invitation_values CHECK (
 created_at IS NOT NULL AND updated_at IS NOT NULL AND token_hash ~ '^[a-f0-9]{64}$' AND email=lower(btrim(email)) AND email ~ '^[^[:space:]@]+@[^[:space:]@]+[.][^[:space:]@]+$' AND expires_at>created_at AND expires_at<=created_at+interval '48 hours' AND
 ((status='pending' AND consumed_at IS NULL AND revoked_at IS NULL AND accepted_user_id IS NULL) OR
 (status='accepted' AND consumed_at IS NOT NULL AND consumed_at<expires_at AND consumed_at>=created_at AND accepted_user_id IS NOT NULL AND revoked_at IS NULL) OR
 (status='revoked' AND revoked_at IS NOT NULL AND revoked_at>=created_at AND consumed_at IS NULL AND accepted_user_id IS NULL)));
CREATE OR REPLACE FUNCTION gt_member_invitation_history() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF TG_OP='DELETE' OR OLD.status<>'pending' OR NEW.status NOT IN ('accepted','revoked') OR (to_jsonb(NEW)-ARRAY['status','consumed_at','revoked_at','accepted_user_id','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['status','consumed_at','revoked_at','accepted_user_id','updated_at']) THEN RAISE EXCEPTION 'Invitation evidence is protected' USING ERRCODE='23514'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER member_invitation_history BEFORE UPDATE OR DELETE ON member_invitations FOR EACH ROW EXECUTE FUNCTION gt_member_invitation_history();
CREATE OR REPLACE FUNCTION gt_member_account_link() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
 IF OLD.user_id IS NOT NULL AND NEW.user_id IS DISTINCT FROM OLD.user_id THEN RAISE EXCEPTION 'Account link cannot be reassigned' USING ERRCODE='23514'; END IF;
 RETURN NEW; END $$;
CREATE TRIGGER member_account_link BEFORE UPDATE ON studio_members FOR EACH ROW EXECUTE FUNCTION gt_member_account_link();
SQL);
        Schema::create('account_security_events', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('action', 32);
            $t->timestampTz('created_at');
            $t->index(['user_id', 'id']);
        });
        DB::statement("ALTER TABLE account_security_events ADD CONSTRAINT account_security_action CHECK(action IN ('password_changed','password_reset','token_issued','token_revoked','tokens_revoked'))");
        DB::statement('CREATE TRIGGER account_security_immutable BEFORE UPDATE OR DELETE ON account_security_events FOR EACH ROW EXECUTE FUNCTION gt_notification_immutable()');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record','membership_plan','member_membership','cash_payment','payment_reversal','platform_notification','notification_preference','member_invitation'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('account_security_events');
        Schema::dropIfExists('member_invitations');
        DB::unprepared('DROP TRIGGER member_account_link ON studio_members; DROP FUNCTION gt_member_account_link(); DROP FUNCTION gt_member_invitation_history(); DROP INDEX member_account_identity;');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record','membership_plan','member_membership','cash_payment','payment_reversal','platform_notification','notification_preference')) NOT VALID");
    }
};
