<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record','membership_plan','member_membership','cash_payment','payment_reversal','platform_notification','notification_preference'))");
        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX notification_membership_identity ON member_memberships(organization_id,studio_member_id,id);
CREATE UNIQUE INDEX notification_cash_identity ON cash_payments(organization_id,studio_member_id,member_membership_id,id);
CREATE UNIQUE INDEX notification_reversal_identity ON payment_reversals(organization_id,cash_payment_id,id);
CREATE UNIQUE INDEX notification_attendance_identity ON attendance_records(organization_id,reservation_id,id);
CREATE TABLE platform_notifications (
 id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, organization_id bigint NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
 studio_member_id bigint, organization_membership_id bigint,
 recipient_key text GENERATED ALWAYS AS (CASE WHEN studio_member_id IS NOT NULL THEN 'member:'||studio_member_id::text ELSE 'staff:'||organization_membership_id::text END) STORED,
 type varchar(40) NOT NULL, event_key varchar(180) NOT NULL, source_fingerprint varchar(64),
 training_session_id bigint, reservation_id bigint, member_membership_id bigint, cash_payment_id bigint, payment_reversal_id bigint, attendance_record_id bigint,
 session_starts_at timestamptz, title_mk varchar(160) NOT NULL, title_en varchar(160) NOT NULL, message_mk varchar(1000) NOT NULL, message_en varchar(1000) NOT NULL,
 metadata jsonb NOT NULL DEFAULT '{}'::jsonb, created_at timestamptz NOT NULL,
 UNIQUE(organization_id,id), UNIQUE(organization_id,recipient_key,event_key),
 CONSTRAINT notification_recipient CHECK (num_nonnulls(studio_member_id,organization_membership_id)=1),
 FOREIGN KEY(organization_id,studio_member_id) REFERENCES studio_members(organization_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,organization_membership_id) REFERENCES organization_memberships(organization_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,training_session_id) REFERENCES training_sessions(organization_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,training_session_id,studio_member_id,reservation_id) REFERENCES reservations(organization_id,training_session_id,studio_member_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,studio_member_id,member_membership_id) REFERENCES member_memberships(organization_id,studio_member_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,studio_member_id,member_membership_id,cash_payment_id) REFERENCES cash_payments(organization_id,studio_member_id,member_membership_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,cash_payment_id,payment_reversal_id) REFERENCES payment_reversals(organization_id,cash_payment_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,reservation_id,attendance_record_id) REFERENCES attendance_records(organization_id,reservation_id,id) ON DELETE RESTRICT,
 CONSTRAINT notification_type_source CHECK (
  (type IN ('reservation.confirmed','reservation.cancelled','waiting.promoted','session.cancelled','session.changed','session.reminder','attendance.result') AND studio_member_id IS NOT NULL AND training_session_id IS NOT NULL AND reservation_id IS NOT NULL AND member_membership_id IS NULL AND cash_payment_id IS NULL AND payment_reversal_id IS NULL AND ((type='attendance.result' AND attendance_record_id IS NOT NULL) OR (type<>'attendance.result' AND attendance_record_id IS NULL))) OR
  (type IN ('membership.created','membership.activated','membership.expiring','membership.expired','cash.recorded','cash.reversed') AND studio_member_id IS NOT NULL AND member_membership_id IS NOT NULL AND training_session_id IS NULL AND reservation_id IS NULL AND attendance_record_id IS NULL AND ((type='cash.recorded' AND cash_payment_id IS NOT NULL AND payment_reversal_id IS NULL) OR (type='cash.reversed' AND cash_payment_id IS NOT NULL AND payment_reversal_id IS NOT NULL) OR (type LIKE 'membership.%' AND cash_payment_id IS NULL AND payment_reversal_id IS NULL))) OR
  (type IN ('staff.session_changed','staff.session_cancelled') AND organization_membership_id IS NOT NULL AND training_session_id IS NOT NULL AND reservation_id IS NULL AND member_membership_id IS NULL AND cash_payment_id IS NULL AND payment_reversal_id IS NULL AND attendance_record_id IS NULL)
 ),
 CHECK (length(event_key)>0 AND length(title_mk)>0 AND length(title_en)>0 AND length(message_mk)>0 AND length(message_en)>0),
 CHECK ((training_session_id IS NULL AND session_starts_at IS NULL) OR (training_session_id IS NOT NULL AND session_starts_at IS NOT NULL)),
 CHECK (source_fingerprint IS NULL OR source_fingerprint ~ '^[a-f0-9]{64}$')
);
CREATE INDEX notification_member_inbox ON platform_notifications(organization_id,studio_member_id,id DESC);
CREATE INDEX notification_staff_inbox ON platform_notifications(organization_id,organization_membership_id,id DESC);
CREATE INDEX notification_reservation_event ON platform_notifications(reservation_id,type,session_starts_at);
CREATE INDEX notification_membership_event ON platform_notifications(member_membership_id,type);
CREATE TABLE notification_deliveries (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, platform_notification_id bigint NOT NULL UNIQUE,
 channel varchar(16) NOT NULL DEFAULT 'in_app' CHECK(channel='in_app'),
 state varchar(16) NOT NULL DEFAULT 'pending' CHECK(state IN ('pending','processing','retry','delivered','suppressed','failed')),
 attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 5), next_attempt_at timestamptz NOT NULL,
 lease_token uuid, lease_expires_at timestamptz, delivered_at timestamptz,
 error_code varchar(32) CHECK(error_code IN ('transient','lease_expired','ineligible','preference','invalid_payload','attempt_limit')),
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(organization_id,id),
 FOREIGN KEY(organization_id,platform_notification_id) REFERENCES platform_notifications(organization_id,id) ON DELETE RESTRICT,
 CHECK ((state='processing' AND lease_token IS NOT NULL AND lease_expires_at IS NOT NULL) OR (state<>'processing' AND lease_token IS NULL AND lease_expires_at IS NULL)),
 CHECK ((state='delivered' AND delivered_at IS NOT NULL) OR (state<>'delivered' AND delivered_at IS NULL))
);
CREATE INDEX notification_delivery_due ON notification_deliveries(state,next_attempt_at,id);
CREATE INDEX notification_delivery_lease ON notification_deliveries(state,lease_expires_at,id);
CREATE TABLE notification_delivery_attempts (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, notification_delivery_id bigint NOT NULL, attempt integer NOT NULL CHECK(attempt BETWEEN 1 AND 5),
 lease_token uuid NOT NULL UNIQUE, outcome varchar(16) NOT NULL CHECK(outcome IN ('started','delivered','retry','failed','suppressed','abandoned')),
 started_at timestamptz NOT NULL, finished_at timestamptz, error_code varchar(32) CHECK(error_code IN ('transient','lease_expired','ineligible','preference','invalid_payload','attempt_limit')),
 UNIQUE(notification_delivery_id,attempt),
 FOREIGN KEY(organization_id,notification_delivery_id) REFERENCES notification_deliveries(organization_id,id) ON DELETE RESTRICT,
 CHECK ((outcome='started' AND finished_at IS NULL) OR (outcome<>'started' AND finished_at IS NOT NULL AND finished_at>=started_at))
);
CREATE TABLE notification_reads (
 id bigserial PRIMARY KEY, organization_id bigint NOT NULL, platform_notification_id bigint NOT NULL UNIQUE, read_at timestamptz NOT NULL,
 FOREIGN KEY(organization_id,platform_notification_id) REFERENCES platform_notifications(organization_id,id) ON DELETE RESTRICT
);
CREATE TABLE notification_preferences (
 id bigserial PRIMARY KEY, public_id uuid NOT NULL UNIQUE, organization_id bigint NOT NULL REFERENCES organizations(id) ON DELETE RESTRICT,
 studio_member_id bigint, organization_membership_id bigint,
 recipient_key text GENERATED ALWAYS AS (CASE WHEN studio_member_id IS NOT NULL THEN 'member:'||studio_member_id::text ELSE 'staff:'||organization_membership_id::text END) STORED,
 reminders_enabled boolean NOT NULL DEFAULT true, expiry_reminders_enabled boolean NOT NULL DEFAULT true,
 created_at timestamptz NOT NULL, updated_at timestamptz NOT NULL,
 UNIQUE(organization_id,recipient_key), CHECK(num_nonnulls(studio_member_id,organization_membership_id)=1),
 FOREIGN KEY(organization_id,studio_member_id) REFERENCES studio_members(organization_id,id) ON DELETE RESTRICT,
 FOREIGN KEY(organization_id,organization_membership_id) REFERENCES organization_memberships(organization_id,id) ON DELETE RESTRICT
);
CREATE OR REPLACE FUNCTION gt_notification_metadata_valid(value jsonb) RETURNS boolean LANGUAGE sql IMMUTABLE AS $$
 SELECT jsonb_typeof(value)='object' AND octet_length(value::text)<=2048 AND value-ARRAY['date','time','timezone','status','starts_on','ends_on']='{}'::jsonb AND NOT EXISTS (SELECT 1 FROM jsonb_each(value) x WHERE jsonb_typeof(x.value)<>'string' OR char_length(x.value::text)>102)
$$;
ALTER TABLE platform_notifications ADD CONSTRAINT notification_metadata CHECK(gt_notification_metadata_valid(metadata));
CREATE OR REPLACE FUNCTION gt_notification_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'Notification evidence is immutable' USING ERRCODE='23514'; END $$;
CREATE TRIGGER notification_immutable BEFORE UPDATE OR DELETE ON platform_notifications FOR EACH ROW EXECUTE FUNCTION gt_notification_immutable();
CREATE TRIGGER notification_read_immutable BEFORE UPDATE OR DELETE ON notification_reads FOR EACH ROW EXECUTE FUNCTION gt_notification_immutable();
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
CREATE OR REPLACE FUNCTION gt_notification_attempt_insert() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.outcome<>'started' OR NOT EXISTS(SELECT 1 FROM notification_deliveries d WHERE d.id=NEW.notification_delivery_id AND d.organization_id=NEW.organization_id AND d.state='processing' AND d.attempts=NEW.attempt AND d.lease_token=NEW.lease_token) THEN RAISE EXCEPTION 'Invalid delivery attempt' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER notification_attempt_insert BEFORE INSERT ON notification_delivery_attempts FOR EACH ROW EXECUTE FUNCTION gt_notification_attempt_insert();
CREATE TRIGGER delivery_mutation BEFORE UPDATE OR DELETE ON notification_deliveries FOR EACH ROW EXECUTE FUNCTION gt_notification_mutation();
CREATE TRIGGER attempt_mutation BEFORE UPDATE OR DELETE ON notification_delivery_attempts FOR EACH ROW EXECUTE FUNCTION gt_notification_mutation();
CREATE TRIGGER preference_mutation BEFORE UPDATE OR DELETE ON notification_preferences FOR EACH ROW EXECUTE FUNCTION gt_notification_mutation();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE notification_preferences,notification_reads,notification_delivery_attempts,notification_deliveries,platform_notifications; DROP FUNCTION gt_notification_mutation(); DROP FUNCTION gt_notification_attempt_insert(); DROP FUNCTION gt_notification_immutable(); DROP FUNCTION gt_notification_metadata_valid(jsonb); DROP INDEX notification_membership_identity; DROP INDEX notification_cash_identity; DROP INDEX notification_reversal_identity; DROP INDEX notification_attendance_identity;');
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record','membership_plan','member_membership','cash_payment','payment_reversal')) NOT VALID");
    }
};
