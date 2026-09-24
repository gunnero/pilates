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
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions)='array' AND permissions <@ '[\"reservations.view\", \"reservations.manage\", \"reservations.cancel\", \"waiting_list.view\", \"waiting_list.manage\", \"permanent_spots.view\", \"permanent_spots.manage\", \"studio.access\", \"organizations.view\", \"organizations.manage\", \"locations.view\", \"locations.manage\", \"staff.view\", \"staff.manage\", \"permissions.view\", \"permissions.manage\", \"sessions.view\", \"sessions.manage\", \"sessions.cancel\", \"groups.view\", \"groups.manage\", \"schedule.view\", \"schedule.manage\", \"trainers.view\", \"trainers.manage\", \"members.view\", \"members.create\", \"members.update\", \"members.status.manage\", \"members.notes.view\", \"members.notes.manage\", \"memberships.view\", \"memberships.manage\", \"attendance.view\", \"attendance.manage\", \"attendance.correct\", \"payments.view\", \"payments.record\", \"payments.correct\", \"reports.view\"]'::jsonb)");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record','membership_plan','member_membership','cash_payment','payment_reversal'))");

        Schema::create('membership_plans', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->string('name', 160);
            $t->text('description')->nullable();
            $t->bigInteger('price_minor');
            $t->string('currency', 3)->default('MKD');
            $t->string('duration_unit', 8);
            $t->integer('duration_count');
            $t->string('status', 16)->default('active');
            $t->timestampsTz();
            $t->unique(['organization_id', 'id']);
            $t->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $t->index(['organization_id', 'status']);
        });
        DB::statement("ALTER TABLE membership_plans ADD CONSTRAINT plan_values CHECK (length(btrim(name))>0 AND char_length(description)<=1000 AND price_minor BETWEEN 0 AND 100000000 AND currency='MKD' AND status IN ('active','inactive','archived') AND ((duration_unit='days' AND duration_count BETWEEN 1 AND 1095) OR (duration_unit='months' AND duration_count BETWEEN 1 AND 36)))");
        Schema::create('member_memberships', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->unsignedBigInteger('membership_plan_id');
            $t->string('entitlement', 32)->default('studio_duration');
            $t->string('plan_name', 160);
            $t->bigInteger('agreed_price_minor');
            $t->string('currency', 3);
            $t->string('duration_unit', 8);
            $t->integer('duration_count');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->timestampTz('cancelled_at')->nullable();
            $t->string('cancellation_reason', 32)->nullable();
            $t->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestampsTz();
            $t->unique(['organization_id', 'studio_member_id', 'currency', 'id'], 'membership_cash_identity');
            $t->foreign(['organization_id', 'studio_member_id'])->references(['organization_id', 'id'])->on('studio_members')->restrictOnDelete();
            $t->foreign(['organization_id', 'membership_plan_id'])->references(['organization_id', 'id'])->on('membership_plans')->restrictOnDelete();
            $t->index(['organization_id', 'ends_on', 'starts_on'], 'membership_period_lookup');
            $t->index(['organization_id', 'studio_member_id']);
        });
        DB::statement("ALTER TABLE member_memberships ADD CONSTRAINT membership_commercial CHECK (entitlement='studio_duration' AND agreed_price_minor BETWEEN 0 AND 100000000 AND currency='MKD' AND length(btrim(plan_name))>0 AND ((duration_unit='days' AND duration_count BETWEEN 1 AND 1095) OR (duration_unit='months' AND duration_count BETWEEN 1 AND 36))), ADD CONSTRAINT membership_dates CHECK (starts_on BETWEEN DATE '2000-01-01' AND DATE '2100-12-31' AND ends_on BETWEEN starts_on AND DATE '2103-12-31'), ADD CONSTRAINT membership_cancellation CHECK ((cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL) OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND cancellation_reason IN ('mistake','member_request','other'))), ADD CONSTRAINT membership_no_overlap EXCLUDE USING gist (int8range(studio_member_id,studio_member_id,'[]') WITH &&, daterange(starts_on,ends_on,'[]') WITH &&) WHERE (cancelled_at IS NULL AND entitlement='studio_duration')");
        Schema::create('cash_payments', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->uuid('request_id');
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('studio_member_id');
            $t->unsignedBigInteger('member_membership_id');
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('method', 8)->default('cash');
            $t->timestampTz('paid_at');
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->string('note', 500)->nullable();
            $t->timestampTz('created_at');
            $t->unique(['organization_id', 'request_id']);
            $t->unique(['organization_id', 'amount_minor', 'currency', 'id'], 'cash_reversal_identity');
            $t->foreign(['organization_id', 'studio_member_id', 'currency', 'member_membership_id'], 'cash_membership_fk')->references(['organization_id', 'studio_member_id', 'currency', 'id'])->on('member_memberships')->restrictOnDelete();
            $t->index(['organization_id', 'member_membership_id']);
            $t->index('member_membership_id');
            $t->index(['organization_id', 'paid_at']);
            $t->index(['organization_id', 'studio_member_id', 'paid_at']);
        });
        DB::statement("ALTER TABLE cash_payments ADD CONSTRAINT cash_values CHECK (amount_minor BETWEEN 1 AND 100000000 AND currency='MKD' AND method='cash' AND paid_at>=TIMESTAMPTZ '2000-01-01 00:00:00+00' AND paid_at<=created_at)");
        Schema::create('payment_reversals', function (Blueprint $t): void {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->unsignedBigInteger('organization_id');
            $t->unsignedBigInteger('cash_payment_id')->unique();
            $t->bigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('reason', 32);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('created_at');
            $t->foreign(['organization_id', 'amount_minor', 'currency', 'cash_payment_id'], 'reversal_cash_fk')->references(['organization_id', 'amount_minor', 'currency', 'id'])->on('cash_payments')->restrictOnDelete();
            $t->index(['organization_id', 'created_at']);
        });
        DB::statement("ALTER TABLE payment_reversals ADD CONSTRAINT reversal_values CHECK (amount_minor>0 AND currency='MKD' AND reason IN ('wrong_member','wrong_amount','wrong_date','duplicate','other'))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION gt_cash_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'Cash ledger is append-only' USING ERRCODE='23514'; END $$;
CREATE TRIGGER cash_immutable BEFORE UPDATE OR DELETE ON cash_payments FOR EACH ROW EXECUTE FUNCTION gt_cash_immutable();
CREATE TRIGGER reversal_immutable BEFORE UPDATE OR DELETE ON payment_reversals FOR EACH ROW EXECUTE FUNCTION gt_cash_immutable();
CREATE OR REPLACE FUNCTION gt_membership_history() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'Membership history is retained' USING ERRCODE='23514'; END IF;
 IF (to_jsonb(NEW) - ARRAY['cancelled_at','cancelled_by','cancellation_reason','updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['cancelled_at','cancelled_by','cancellation_reason','updated_at']) OR OLD.cancelled_at IS NOT NULL THEN
 RAISE EXCEPTION 'Membership snapshot is immutable' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER membership_history BEFORE UPDATE OR DELETE ON member_memberships FOR EACH ROW EXECUTE FUNCTION gt_membership_history();
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
        Schema::dropIfExists('cash_payments');
        Schema::dropIfExists('member_memberships');
        Schema::dropIfExists('membership_plans');
        DB::statement('DROP FUNCTION gt_cash_immutable()');
        DB::statement('DROP FUNCTION gt_membership_history()');
        DB::statement('ALTER TABLE organization_memberships DROP CONSTRAINT membership_permissions_check');
        DB::statement("ALTER TABLE organization_memberships ADD CONSTRAINT membership_permissions_check CHECK (jsonb_typeof(permissions)='array' AND permissions <@ '[\"reservations.view\", \"reservations.manage\", \"reservations.cancel\", \"waiting_list.view\", \"waiting_list.manage\", \"permanent_spots.view\", \"permanent_spots.manage\", \"studio.access\", \"organizations.view\", \"organizations.manage\", \"locations.view\", \"locations.manage\", \"staff.view\", \"staff.manage\", \"permissions.view\", \"permissions.manage\", \"sessions.view\", \"sessions.manage\", \"sessions.cancel\", \"groups.view\", \"groups.manage\", \"schedule.view\", \"schedule.manage\", \"trainers.view\", \"trainers.manage\", \"members.view\", \"members.create\", \"members.update\", \"members.status.manage\", \"members.notes.view\", \"members.notes.manage\", \"attendance.view\", \"attendance.manage\", \"attendance.correct\", \"payments.view\", \"payments.record\", \"payments.correct\", \"reports.view\"]'::jsonb) NOT VALID");
        DB::statement('ALTER TABLE audit_events DROP CONSTRAINT audit_subject_check');
        DB::statement("ALTER TABLE audit_events ADD CONSTRAINT audit_subject_check CHECK (subject_type IN ('organization','location','organization_membership','studio_member','trainer_profile','training_group','schedule_rule','training_session','reservation','waiting_list_entry','permanent_spot','attendance_record')) NOT VALID");

    }
};
