<?php

use App\Http\Middleware\Locale;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(SecurityHeaders::class);
        $middleware->append(Locale::class);
        $middleware->statefulApi();
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*', 'auth/*') || $request->expectsJson());
        // Framework exception messages can contain submitted values or SQL. Public errors are bounded.
        $exceptions->respond(function (Response $response): Response {
            if (request()->is('api/*', 'auth/*') && $response->getStatusCode() >= 400 && $response->getStatusCode() !== 422) {
                $status = $response->getStatusCode();
                $headers = array_filter(['Retry-After' => $response->headers->get('Retry-After')]);

                return response()->json(['message' => __('errors.'.(in_array($status, [401, 403, 404, 409, 419, 429], true) ? $status : 500))], $status, $headers);
            }

            return $response;
        });
        // Do not log exception messages, SQL bindings, headers, URLs or request bodies.
        $exceptions->report(function (Throwable $exception): bool {
            Log::error('Unhandled application exception', ['type' => $exception::class]);

            return false;
        });
    })->create();
