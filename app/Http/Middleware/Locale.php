<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class Locale
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($request->getPreferredLanguage(config('studio.locales')) ?? config('app.locale'));
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $response->setVary('Accept-Language', false);

        return $response;
    }
}
