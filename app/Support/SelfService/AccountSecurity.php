<?php

namespace App\Support\SelfService;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountSecurity
{
    /** Serializes account creation and broker consumption, including across studio invitations.
     * @template T
     *
     * @param  \Closure():T  $operation
     * @return T
     */
    public static function run(string $email, \Closure $operation): mixed
    {
        return DB::transaction(function () use ($email, $operation) {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['gt-account:'.$email]);

            return $operation();
        }, 3);
    }

    public static function audit(User $u, string $action): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Account audit requires transaction');
        }
        DB::table('account_security_events')->insert(['public_id' => (string) Str::uuid7(), 'user_id' => $u->id, 'action' => $action, 'created_at' => now()]);
    }

    public static function revoke(User $u): void
    {
        if (config('session.driver') !== 'database' || (config('session.connection') !== null && config('session.connection') !== config('database.default'))) {
            throw new \LogicException('Atomic credential revocation requires shared database sessions');
        }
        DB::table(config('session.table'))->where('user_id', $u->id)->delete();
        $u->tokens()->delete();
    }
}
