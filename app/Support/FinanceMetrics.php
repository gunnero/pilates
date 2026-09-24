<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

final class FinanceMetrics
{
    /** Internal query foundation; HTTP callers require payments.view AND reports.view.
     * Net cash is attributed to the original paid_at date, including later reversals.
     *
     * @return array<string,int>
     */
    public static function forOrganization(Organization $organization): array
    {
        $today = now($organization->timezone)->startOfDay();
        $row = DB::selectOne(<<<'SQL'
SELECT
 (SELECT count(*) FROM member_memberships m WHERE m.organization_id=? AND m.cancelled_at IS NULL AND m.starts_on<=? AND m.ends_on>=?) AS active,
 (SELECT count(*) FROM member_memberships m WHERE m.organization_id=? AND m.cancelled_at IS NULL AND m.starts_on<=? AND m.ends_on BETWEEN ? AND ?) AS expiring,
 (SELECT count(*) FROM member_memberships m WHERE m.organization_id=? AND m.agreed_price_minor>(SELECT coalesce(sum(p.amount_minor),0) FROM cash_payments p WHERE p.member_membership_id=m.id AND NOT EXISTS(SELECT 1 FROM payment_reversals r WHERE r.cash_payment_id=p.id))) AS outstanding,
 (SELECT coalesce(sum(p.amount_minor),0) FROM cash_payments p WHERE p.organization_id=? AND p.paid_at>=? AND p.paid_at<? AND NOT EXISTS(SELECT 1 FROM payment_reversals r WHERE r.cash_payment_id=p.id)) AS cash_today_minor,
 (SELECT coalesce(sum(p.amount_minor),0) FROM cash_payments p WHERE p.organization_id=? AND p.paid_at>=? AND p.paid_at<? AND NOT EXISTS(SELECT 1 FROM payment_reversals r WHERE r.cash_payment_id=p.id)) AS cash_month_minor
SQL, [$organization->id, $today->toDateString(), $today->toDateString(), $organization->id, $today->toDateString(), $today->toDateString(), $today->copy()->addDays(MembershipPeriod::EXPIRING_DAYS)->toDateString(), $organization->id, $organization->id, $today->copy()->utc(), $today->copy()->addDay()->utc(), $organization->id, $today->copy()->startOfMonth()->utc(), $today->copy()->addMonthNoOverflow()->startOfMonth()->utc()]);

        return array_map(intval(...), (array) $row);
    }
}
