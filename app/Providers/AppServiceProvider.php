<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permission;
use App\Support\TenantContext;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes();
        if ($this->app->environment('production')) {
            config(['app.debug' => false]);
            if (config('session.driver') !== 'database') {
                throw new \LogicException('Production requires database sessions for credential revocation.');
            }
        }
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => app(TenantContext::class)->permits($user, $permission)
                && ($user->currentAccessToken() === null || $user->tokenCan($permission->value)));
        }
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $key = hash('sha256', is_string($email) ? mb_strtolower(trim($email)) : '');

            return [Limit::perMinute(30)->by('ip:'.$request->ip()), Limit::perMinute(5)->by('account:'.$key)];
        });
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)->by((string) $request->ip()));
        ResetPassword::createUrlUsing(fn (User $user, string $token): string => rtrim(config('app.url'), '/').'/reset-password#'.http_build_query(['token' => $token, 'email' => $user->email]));
    }
}
