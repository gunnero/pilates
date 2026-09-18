<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function __construct(private TenantContext $context) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $this->context->clear();
        try {
            $user = $request->user();
            abort_unless($user instanceof User, 401);
            $organization = $request->route('organization');
            abort_unless(is_string($organization), 404);
            $this->context->resolve($user, $organization);

            return $next($request);
        } finally {
            $this->context->clear();
        }
    }
}
