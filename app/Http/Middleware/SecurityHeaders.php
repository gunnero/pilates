<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $script = "'self'";
        $connect = "'self'";
        $style = "'self'";
        if (app()->environment('local')) {
            $script .= " http://127.0.0.1:5173 'unsafe-inline'";
            $connect .= ' http://127.0.0.1:5173 ws://127.0.0.1:5173';
            $style .= " 'unsafe-inline' http://127.0.0.1:5173";
        }
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src {$script}; style-src {$style}; connect-src {$connect}; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
        if ($request->is('api/*', 'auth/*', 'sanctum/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
