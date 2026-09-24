<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE studio_announcements (
 id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, organization_id bigint NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
 created_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT, group_id bigint,
 request_id uuid NOT NULL, title_mk varchar(160) NOT NULL, title_en varchar(160) NOT NULL,
 message_mk varchar(1000) NOT NULL, message_en varchar(1000) NOT NULL,
 state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','complete','cancelled')),
 cursor_id bigint NOT NULL DEFAULT 0 CHECK(cursor_id>=0), upper_member_id bigint NOT NULL CHECK(upper_member_id>=0),
 created_at timestamptz NOT NULL, expires_at timestamptz NOT NULL, cancelled_at timestamptz,
 UNIQUE(organization_id,id), UNIQUE(organization_id,request_id),
 FOREIGN KEY(organization_id,group_id) REFERENCES training_groups(organization_id,id) ON DELETE RESTRICT,
 CHECK(expires_at>created_at AND expires_at<=created_at+interval '30 days'),
 CHECK(length(title_mk)>0 AND length(title_en)>0 AND length(message_mk)>0 AND length(message_en)>0),
 CHECK((state='cancelled')=(cancelled_at IS NOT NULL))
);
CREATE OR REPLACE FUNCTION gt_announcement_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (to_jsonb(NEW)-ARRAY['state','cursor_id','cancelled_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','cursor_id','cancelled_at']) OR NEW.cursor_id<OLD.cursor_id OR NEW.cursor_id>NEW.upper_member_id OR OLD.state='cancelled' OR (OLD.state='complete' AND NEW.state<>'cancelled') THEN RAISE EXCEPTION 'Invalid announcement mutation' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER announcement_mutation BEFORE UPDATE OR DELETE ON studio_announcements FOR EACH ROW EXECUTE FUNCTION gt_announcement_mutation();
ALTER TABLE platform_notifications ADD COLUMN announcement_id bigint;
ALTER TABLE platform_notifications ADD COLUMN waiting_list_entry_id bigint;
ALTER TABLE platform_notifications ADD FOREIGN KEY(organization_id,announcement_id) REFERENCES studio_announcements(organization_id,id) ON DELETE RESTRICT;
CREATE INDEX announcement_recipients ON platform_notifications(announcement_id) WHERE announcement_id IS NOT NULL;
CREATE UNIQUE INDEX waiting_notification_identity ON waiting_list_entries(organization_id,training_session_id,studio_member_id,id);
ALTER TABLE platform_notifications ADD FOREIGN KEY(organization_id,training_session_id,studio_member_id,waiting_list_entry_id) REFERENCES waiting_list_entries(organization_id,training_session_id,studio_member_id,id) ON DELETE RESTRICT;
SQL);
        $expression = <<<'SQL'

  (type IN ('reservation.confirmed','reservation.cancelled','waiting.promoted','session.cancelled','session.changed','session.reminder','attendance.result') AND studio_member_id IS NOT NULL AND training_session_id IS NOT NULL AND reservation_id IS NOT NULL AND member_membership_id IS NULL AND cash_payment_id IS NULL AND payment_reversal_id IS NULL AND ((type='attendance.result' AND attendance_record_id IS NOT NULL) OR (type<>'attendance.result' AND attendance_record_id IS NULL))) OR
  (type IN ('membership.created','membership.activated','membership.expiring','membership.expired','cash.recorded','cash.reversed') AND studio_member_id IS NOT NULL AND member_membership_id IS NOT NULL AND training_session_id IS NULL AND reservation_id IS NULL AND attendance_record_id IS NULL AND ((type='cash.recorded' AND cash_payment_id IS NOT NULL AND payment_reversal_id IS NULL) OR (type='cash.reversed' AND cash_payment_id IS NOT NULL AND payment_reversal_id IS NOT NULL) OR (type LIKE 'membership.%' AND cash_payment_id IS NULL AND payment_reversal_id IS NULL))) OR
  (type IN ('staff.session_changed','staff.session_cancelled') AND organization_membership_id IS NOT NULL AND training_session_id IS NOT NULL AND reservation_id IS NULL AND member_membership_id IS NULL AND cash_payment_id IS NULL AND payment_reversal_id IS NULL AND attendance_record_id IS NULL)

SQL;
        DB::statement('ALTER TABLE platform_notifications DROP CONSTRAINT notification_type_source');
        DB::statement("ALTER TABLE platform_notifications ADD CONSTRAINT notification_type_source CHECK ((($expression) AND announcement_id IS NULL AND waiting_list_entry_id IS NULL) OR (type='studio.announcement' AND studio_member_id IS NOT NULL AND announcement_id IS NOT NULL AND num_nonnulls(training_session_id,reservation_id,member_membership_id,cash_payment_id,payment_reversal_id,attendance_record_id,waiting_list_entry_id)=0) OR (type IN ('waiting.joined','waiting.expired','waiting.session_cancelled') AND studio_member_id IS NOT NULL AND training_session_id IS NOT NULL AND waiting_list_entry_id IS NOT NULL AND num_nonnulls(reservation_id,member_membership_id,cash_payment_id,payment_reversal_id,attendance_record_id,announcement_id)=0))");
        DB::unprepared(<<<'SQL'
CREATE TABLE push_token_owners (
 token_hash char(64) PRIMARY KEY CHECK(token_hash ~ '^[a-f0-9]{64}$'),
 user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 UNIQUE(token_hash,user_id)
);
CREATE TRIGGER push_owner_immutable BEFORE UPDATE OR DELETE ON push_token_owners FOR EACH ROW EXECUTE FUNCTION gt_notification_immutable();
CREATE TABLE device_push_tokens (
 id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, organization_id bigint NOT NULL,
 studio_member_id bigint NOT NULL, user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 access_token_id bigint REFERENCES personal_access_tokens(id) ON DELETE SET NULL,
 token_hash char(64) NOT NULL CHECK(token_hash ~ '^[a-f0-9]{64}$'), token_ciphertext text NOT NULL,
 platform varchar(8) NOT NULL CHECK(platform IN ('ios','android')), installation_id uuid NOT NULL,
 last_seen_at timestamptz NOT NULL, revoked_at timestamptz, created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(organization_id,id), UNIQUE(organization_id,studio_member_id,id), UNIQUE(organization_id,token_hash),
 FOREIGN KEY(token_hash,user_id) REFERENCES push_token_owners(token_hash,user_id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,studio_member_id) REFERENCES studio_members(organization_id,id) ON DELETE RESTRICT
);
CREATE INDEX push_recipient_active ON device_push_tokens(organization_id,studio_member_id) WHERE revoked_at IS NULL;
CREATE INDEX push_login ON device_push_tokens(access_token_id);
CREATE OR REPLACE FUNCTION gt_push_ownership() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Device evidence is retained' USING ERRCODE='23514'; END IF;
 IF TG_OP='UPDATE' AND (to_jsonb(NEW)-ARRAY['access_token_id','last_seen_at','revoked_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['access_token_id','last_seen_at','revoked_at','updated_at']) THEN RAISE EXCEPTION 'Device ownership is immutable' USING ERRCODE='23514'; END IF;
 IF NOT EXISTS(SELECT 1 FROM studio_members WHERE id=NEW.studio_member_id AND organization_id=NEW.organization_id AND user_id=NEW.user_id) OR (NEW.access_token_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM personal_access_tokens WHERE id=NEW.access_token_id AND tokenable_id=NEW.user_id AND tokenable_type='App\Models\User')) THEN RAISE EXCEPTION 'Invalid device identity' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER push_ownership BEFORE INSERT OR UPDATE OR DELETE ON device_push_tokens FOR EACH ROW EXECUTE FUNCTION gt_push_ownership();
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_platform_notification_id_key;
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_channel_check;
ALTER TABLE notification_deliveries ADD COLUMN device_push_token_id bigint;
ALTER TABLE notification_deliveries ADD COLUMN destination_user_id bigint REFERENCES users(id) ON DELETE RESTRICT;
ALTER TABLE notification_deliveries ADD COLUMN destination_key varchar(80) NOT NULL DEFAULT 'in_app';
ALTER TABLE notification_deliveries ADD CONSTRAINT delivery_channel CHECK(channel IN ('in_app','push','email'));
ALTER TABLE notification_deliveries ADD CONSTRAINT delivery_destination CHECK (
 (channel='in_app' AND device_push_token_id IS NULL AND destination_user_id IS NULL AND destination_key='in_app') OR
 (channel='push' AND device_push_token_id IS NOT NULL AND destination_user_id IS NOT NULL AND destination_key='device:'||device_push_token_id::text) OR
 (channel='email' AND device_push_token_id IS NULL AND destination_user_id IS NOT NULL AND destination_key='user:'||destination_user_id::text));
ALTER TABLE notification_deliveries ADD FOREIGN KEY(organization_id,device_push_token_id) REFERENCES device_push_tokens(organization_id,id) ON DELETE RESTRICT;
ALTER TABLE notification_deliveries ADD CONSTRAINT delivery_destination_unique UNIQUE(platform_notification_id,channel,destination_key);
CREATE OR REPLACE FUNCTION gt_delivery_recipient() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.channel<>'in_app' AND NOT EXISTS(SELECT 1 FROM platform_notifications n JOIN studio_members m ON m.id=n.studio_member_id AND m.organization_id=n.organization_id WHERE n.id=NEW.platform_notification_id AND n.organization_id=NEW.organization_id AND m.user_id=NEW.destination_user_id AND (NEW.channel='email' OR EXISTS(SELECT 1 FROM device_push_tokens d WHERE d.id=NEW.device_push_token_id AND d.organization_id=n.organization_id AND d.studio_member_id=m.id AND d.user_id=m.user_id))) THEN RAISE EXCEPTION 'Foreign delivery recipient' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER delivery_recipient BEFORE INSERT ON notification_deliveries FOR EACH ROW EXECUTE FUNCTION gt_delivery_recipient();
ALTER TABLE notification_preferences ADD COLUMN session_changes_enabled boolean NOT NULL DEFAULT true;
ALTER TABLE notification_preferences ADD COLUMN waiting_updates_enabled boolean NOT NULL DEFAULT true;
ALTER TABLE notification_preferences ADD COLUMN announcements_enabled boolean NOT NULL DEFAULT true;
ALTER TABLE notification_preferences ADD COLUMN push_enabled boolean NOT NULL DEFAULT true;
ALTER TABLE notification_preferences ADD COLUMN email_enabled boolean NOT NULL DEFAULT false;
CREATE OR REPLACE FUNCTION gt_communication_preferences() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' OR (to_jsonb(NEW)-ARRAY['reminders_enabled','expiry_reminders_enabled','session_changes_enabled','waiting_updates_enabled','announcements_enabled','push_enabled','email_enabled','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['reminders_enabled','expiry_reminders_enabled','session_changes_enabled','waiting_updates_enabled','announcements_enabled','push_enabled','email_enabled','updated_at']) THEN RAISE EXCEPTION 'Preference identity is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
DROP TRIGGER preference_mutation ON notification_preferences;
CREATE TRIGGER preference_mutation BEFORE UPDATE OR DELETE ON notification_preferences FOR EACH ROW EXECUTE FUNCTION gt_communication_preferences();
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_error_code_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_error_code_check CHECK(error_code IN ('transient','lease_expired','ineligible','preference','invalid_payload','attempt_limit','invalid_device','permanent','disabled','receipt_timeout'));
ALTER TABLE notification_delivery_attempts DROP CONSTRAINT notification_delivery_attempts_error_code_check;
ALTER TABLE notification_delivery_attempts ADD CONSTRAINT notification_delivery_attempts_error_code_check CHECK(error_code IN ('transient','lease_expired','ineligible','preference','invalid_payload','attempt_limit','invalid_device','permanent','disabled','receipt_timeout'));
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_state_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_state_check CHECK(state IN ('pending','processing','awaiting_receipt','retry','delivered','suppressed','failed'));
ALTER TABLE notification_delivery_attempts DROP CONSTRAINT notification_delivery_attempts_outcome_check;
ALTER TABLE notification_delivery_attempts ADD CONSTRAINT notification_delivery_attempts_outcome_check CHECK(outcome IN ('started','awaiting_receipt','delivered','retry','failed','suppressed','abandoned'));
CREATE OR REPLACE FUNCTION gt_notification_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allowed text[];
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Notification history is retained' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='notification_deliveries' THEN
  allowed:=ARRAY['state','attempts','next_attempt_at','lease_token','lease_expires_at','delivered_at','error_code','updated_at'];
  IF OLD.state IN ('delivered','suppressed','failed') OR NEW.attempts<OLD.attempts OR NEW.attempts>OLD.attempts+1 OR
    (NEW.state='processing' AND (NEW.attempts<>OLD.attempts+1 OR NEW.lease_token IS NOT DISTINCT FROM OLD.lease_token)) OR
    (NEW.state<>'processing' AND NEW.attempts<>OLD.attempts) OR
    (OLD.state IN ('pending','retry') AND NEW.state NOT IN ('processing','suppressed')) OR
    (OLD.state='processing' AND NEW.state NOT IN ('processing','awaiting_receipt','retry','delivered','suppressed','failed')) OR (OLD.state='awaiting_receipt' AND NEW.state NOT IN ('retry','delivered','suppressed','failed')) THEN RAISE EXCEPTION 'Invalid delivery transition' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='notification_delivery_attempts' THEN
  allowed:=ARRAY['outcome','finished_at','error_code'];
  IF OLD.outcome<>'started' OR NEW.outcome='started' THEN RAISE EXCEPTION 'Attempt is final' USING ERRCODE='23514'; END IF;
 ELSE allowed:=ARRAY['reminders_enabled','expiry_reminders_enabled','updated_at']; END IF;
 IF (to_jsonb(NEW)-allowed) IS DISTINCT FROM (to_jsonb(OLD)-allowed) THEN RAISE EXCEPTION 'Notification ownership is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TABLE notification_push_receipts (
 id bigserial PRIMARY KEY, notification_delivery_id bigint NOT NULL REFERENCES notification_deliveries(id) ON DELETE RESTRICT,
 attempt integer NOT NULL CHECK(attempt BETWEEN 1 AND 5),
 UNIQUE(notification_delivery_id,attempt),
 FOREIGN KEY(notification_delivery_id,attempt) REFERENCES notification_delivery_attempts(notification_delivery_id,attempt) ON DELETE RESTRICT,
 provider_id varchar(200) NOT NULL UNIQUE, state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','accepted','failed','unknown')),
 next_check_at timestamptz NOT NULL, created_at timestamptz NOT NULL, checked_at timestamptz,
 error_code varchar(32) CHECK(error_code IN ('invalid_device','provider_failure','receipt_timeout'))
);
CREATE INDEX push_receipts_due ON notification_push_receipts(state,next_check_at);
CREATE OR REPLACE FUNCTION gt_push_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Receipt evidence retained' USING ERRCODE='23514'; END IF;
 IF TG_OP='INSERT' THEN
  IF NEW.state<>'pending' OR NOT EXISTS(SELECT 1 FROM notification_deliveries WHERE id=NEW.notification_delivery_id AND channel='push' AND state='processing' AND attempts=NEW.attempt) THEN RAISE EXCEPTION 'Invalid receipt source' USING ERRCODE='23514'; END IF;
 ELSE
  IF OLD.state<>'pending' OR (to_jsonb(NEW)-ARRAY['state','next_check_at','checked_at','error_code']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['state','next_check_at','checked_at','error_code']) THEN RAISE EXCEPTION 'Invalid receipt mutation' USING ERRCODE='23514'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER push_receipt_guard BEFORE INSERT OR UPDATE OR DELETE ON notification_push_receipts FOR EACH ROW EXECUTE FUNCTION gt_push_receipt_guard();
SQL);
    }

    public function down(): void
    {
        // A populated rollback is supported only when this whole migration batch is
        // being removed (test/fresh-install teardown also drops the GT-010 tables).
        $externalHistory = DB::table('notification_deliveries')->where('channel', '<>', 'in_app')->exists();
        $batch = DB::table('migrations')->where('migration', '2026_09_24_000005_extend_communication_engine')->value('batch');
        $foundationBatch = DB::table('migrations')->where('migration', '2026_09_24_000002_establish_notifications')->value('batch');
        $newHistory = $externalHistory || DB::table('studio_announcements')->exists() || DB::table('device_push_tokens')->exists() || DB::table('platform_notifications')->whereNotNull('waiting_list_entry_id')->exists();
        if ($batch !== $foundationBatch && $newHistory) {
            throw new RuntimeException('Communication history exists; use a reviewed forward migration.');
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE platform_notifications DROP CONSTRAINT notification_type_source;
ALTER TABLE platform_notifications DROP COLUMN announcement_id, DROP COLUMN waiting_list_entry_id;
ALTER TABLE platform_notifications ADD CONSTRAINT notification_type_source CHECK (
  (type IN ('reservation.confirmed','reservation.cancelled','waiting.promoted','session.cancelled','session.changed','session.reminder','attendance.result') AND studio_member_id IS NOT NULL AND training_session_id IS NOT NULL AND reservation_id IS NOT NULL AND member_membership_id IS NULL AND cash_payment_id IS NULL AND payment_reversal_id IS NULL AND ((type='attendance.result' AND attendance_record_id IS NOT NULL) OR (type<>'attendance.result' AND attendance_record_id IS NULL))) OR
  (type IN ('membership.created','membership.activated','membership.expiring','membership.expired','cash.recorded','cash.reversed') AND studio_member_id IS NOT NULL AND member_membership_id IS NOT NULL AND training_session_id IS NULL AND reservation_id IS NULL AND attendance_record_id IS NULL AND ((type='cash.recorded' AND cash_payment_id IS NOT NULL AND payment_reversal_id IS NULL) OR (type='cash.reversed' AND cash_payment_id IS NOT NULL AND payment_reversal_id IS NOT NULL) OR (type LIKE 'membership.%' AND cash_payment_id IS NULL AND payment_reversal_id IS NULL))) OR
  (type IN ('staff.session_changed','staff.session_cancelled') AND organization_membership_id IS NOT NULL AND training_session_id IS NOT NULL AND reservation_id IS NULL AND member_membership_id IS NULL AND cash_payment_id IS NULL AND payment_reversal_id IS NULL AND attendance_record_id IS NULL)
 ) NOT VALID;
DROP INDEX waiting_notification_identity;
DROP TABLE studio_announcements;
DROP FUNCTION gt_announcement_mutation();
CREATE OR REPLACE FUNCTION gt_notification_mutation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE allowed text[];
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Notification history is retained' USING ERRCODE='23514'; END IF;
 IF TG_TABLE_NAME='notification_deliveries' THEN
  allowed:=ARRAY['state','attempts','next_attempt_at','lease_token','lease_expires_at','delivered_at','error_code','updated_at'];
  IF OLD.state IN ('delivered','suppressed','failed') OR NEW.attempts<OLD.attempts OR NEW.attempts>OLD.attempts+1 OR
    (NEW.state='processing' AND (NEW.attempts<>OLD.attempts+1 OR NEW.lease_token IS NOT DISTINCT FROM OLD.lease_token)) OR
    (NEW.state<>'processing' AND NEW.attempts<>OLD.attempts) OR
    (OLD.state IN ('pending','retry') AND NEW.state NOT IN ('processing','suppressed')) OR
    (OLD.state='processing' AND NEW.state NOT IN ('processing','retry','delivered','suppressed','failed')) THEN RAISE EXCEPTION 'Invalid delivery transition' USING ERRCODE='23514'; END IF;
 ELSIF TG_TABLE_NAME='notification_delivery_attempts' THEN
  allowed:=ARRAY['outcome','finished_at','error_code'];
  IF OLD.outcome<>'started' OR NEW.outcome='started' THEN RAISE EXCEPTION 'Attempt is final' USING ERRCODE='23514'; END IF;
 ELSE allowed:=ARRAY['reminders_enabled','expiry_reminders_enabled','updated_at']; END IF;
 IF (to_jsonb(NEW)-allowed) IS DISTINCT FROM (to_jsonb(OLD)-allowed) THEN RAISE EXCEPTION 'Notification ownership is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
DROP TABLE notification_push_receipts;
DROP FUNCTION gt_push_receipt_guard();
DROP TRIGGER delivery_recipient ON notification_deliveries;
DROP FUNCTION gt_delivery_recipient();
ALTER TABLE notification_deliveries DROP CONSTRAINT delivery_destination_unique;
ALTER TABLE notification_deliveries DROP CONSTRAINT delivery_destination;
ALTER TABLE notification_deliveries DROP CONSTRAINT delivery_channel;
ALTER TABLE notification_deliveries DROP COLUMN device_push_token_id, DROP COLUMN destination_user_id, DROP COLUMN destination_key;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check CHECK(channel='in_app') NOT VALID;
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_state_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_state_check CHECK(state IN ('pending','processing','retry','delivered','suppressed','failed')) NOT VALID;
ALTER TABLE notification_delivery_attempts DROP CONSTRAINT notification_delivery_attempts_outcome_check;
ALTER TABLE notification_delivery_attempts ADD CONSTRAINT notification_delivery_attempts_outcome_check CHECK(outcome IN ('started','delivered','retry','failed','suppressed','abandoned')) NOT VALID;
ALTER TABLE notification_deliveries DROP CONSTRAINT notification_deliveries_error_code_check;
ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_error_code_check CHECK(error_code IN ('transient','lease_expired','ineligible','preference','invalid_payload','attempt_limit')) NOT VALID;
ALTER TABLE notification_delivery_attempts DROP CONSTRAINT notification_delivery_attempts_error_code_check;
ALTER TABLE notification_delivery_attempts ADD CONSTRAINT notification_delivery_attempts_error_code_check CHECK(error_code IN ('transient','lease_expired','ineligible','preference','invalid_payload','attempt_limit')) NOT VALID;
DROP TABLE device_push_tokens;
DROP TABLE push_token_owners;
DROP FUNCTION gt_push_ownership();
DROP TRIGGER preference_mutation ON notification_preferences;
DROP FUNCTION gt_communication_preferences();
ALTER TABLE notification_preferences DROP COLUMN session_changes_enabled, DROP COLUMN waiting_updates_enabled, DROP COLUMN announcements_enabled, DROP COLUMN push_enabled, DROP COLUMN email_enabled;
CREATE TRIGGER preference_mutation BEFORE UPDATE OR DELETE ON notification_preferences FOR EACH ROW EXECUTE FUNCTION gt_notification_mutation();
SQL);
        if (! $externalHistory) {
            DB::statement('ALTER TABLE notification_deliveries ADD UNIQUE(platform_notification_id)');
        }
        if (! $newHistory) {
            DB::unprepared('ALTER TABLE platform_notifications VALIDATE CONSTRAINT notification_type_source; ALTER TABLE notification_deliveries VALIDATE CONSTRAINT notification_deliveries_channel_check; ALTER TABLE notification_deliveries VALIDATE CONSTRAINT notification_deliveries_state_check; ALTER TABLE notification_delivery_attempts VALIDATE CONSTRAINT notification_delivery_attempts_outcome_check; ALTER TABLE notification_deliveries VALIDATE CONSTRAINT notification_deliveries_error_code_check; ALTER TABLE notification_delivery_attempts VALIDATE CONSTRAINT notification_delivery_attempts_error_code_check;');
        }
    }
};
