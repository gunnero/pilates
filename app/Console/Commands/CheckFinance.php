<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckFinance extends Command
{
    protected $signature = 'gt:finance:check';

    protected $description = 'Read-only financial ownership, reversal, overlap and arithmetic consistency counts';

    public function handle(): int
    {
        $queries = [
            'ownership' => 'SELECT count(*) n FROM cash_payments p LEFT JOIN member_memberships m ON m.id=p.member_membership_id WHERE m.id IS NULL OR (p.organization_id,p.studio_member_id,p.currency) IS DISTINCT FROM (m.organization_id,m.studio_member_id,m.currency)',
            'duplicate_reversals' => 'SELECT count(*) n FROM (SELECT cash_payment_id FROM payment_reversals GROUP BY cash_payment_id HAVING count(*)>1) x',
            'reversal_values' => 'SELECT count(*) n FROM payment_reversals r LEFT JOIN cash_payments p ON p.id=r.cash_payment_id WHERE p.id IS NULL OR (p.organization_id,p.amount_minor,p.currency) IS DISTINCT FROM (r.organization_id,r.amount_minor,r.currency)',
            'overlap' => 'SELECT count(*) n FROM member_memberships a JOIN member_memberships b ON a.id<b.id AND a.studio_member_id=b.studio_member_id AND a.entitlement=b.entitlement AND a.starts_on<=b.ends_on AND b.starts_on<=a.ends_on WHERE a.cancelled_at IS NULL AND b.cancelled_at IS NULL',
            'net_arithmetic' => 'SELECT count(*) n FROM member_memberships m WHERE (SELECT coalesce(sum(p.amount_minor),0) FROM cash_payments p WHERE p.member_membership_id=m.id)-(SELECT coalesce(sum(r.amount_minor),0) FROM payment_reversals r JOIN cash_payments p ON p.id=r.cash_payment_id WHERE p.member_membership_id=m.id) IS DISTINCT FROM (SELECT coalesce(sum(p.amount_minor),0) FROM cash_payments p WHERE p.member_membership_id=m.id AND NOT EXISTS(SELECT 1 FROM payment_reversals r WHERE r.cash_payment_id=p.id))',
        ];
        $counts = [];
        foreach ($queries as $name => $sql) {
            $counts[$name] = (int) DB::selectOne($sql)->n;
        }
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return array_sum($counts) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
