<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie auth only works when Sanctum treats the request as coming from our SPA.
 * Without this, a missing Origin/Referer header surfaces as a confusing 500.
 */
class RequireStatefulRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->hasSession(), 400, 'Send an Origin or Referer header matching SANCTUM_STATEFUL_DOMAINS (for example http://localhost:5173).');

        return $next($request);
    }
}
