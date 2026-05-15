<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * stocs-auth is a headless API — every endpoint must return JSON.
 *
 * Forcing `Accept: application/json` on inbound requests makes Laravel's
 * default exception rendering path (validation, auth, throttle, etc.) emit
 * JSON regardless of what the client sent. Paired with shouldRenderJsonWhen()
 * in bootstrap/app.php this prevents HTML stack traces and the welcome
 * view ever leaking to a caller.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');

        return $next($request);
    }
}
