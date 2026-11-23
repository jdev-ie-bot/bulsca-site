<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

class RequireAuthentikSession
{
    public function handle(Request $request, Closure $next)
    {
        $identity = $request->hasSession() ? $request->session()->get('authentik_session') : null;
        if (! config('services.authentik.enabled') || ! is_array($identity)
            || ($identity['user_id'] ?? null) !== $request->user()?->getAuthIdentifier()
            || ($identity['issuer'] ?? null) !== config('services.authentik.issuer')) {
            throw new AuthenticationException();
        }

        return $next($request);
    }
}
