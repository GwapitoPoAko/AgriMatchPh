<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Session\Middleware\StartSession;

/**
 * Sanctum normally adds the web cookie/session middleware when it recognizes
 * a first-party SPA request. Some local proxy/browser combinations omit the
 * origin header Sanctum uses for that detection, so auth routes need a safe
 * fallback. The regular stateful Sanctum stack wins when it already started
 * the session; this middleware only runs it when the request has no session.
 */
class EnsureApiSession
{
    public function handle(Request $request, Closure $next, string $csrfMode = 'auto')
    {
        if ($csrfMode === 'auto' && $request->bearerToken()) {
            return $next($request);
        }

        if ($request->hasSession()) {
            return $next($request);
        }

        $middleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
        ];

        // Session-cookie mutations need CSRF protection even if Sanctum did
        // not classify the request as stateful. Bearer API clients remain
        // usable without an XSRF cookie.
        if ($csrfMode === 'always' || ! $request->bearerToken()) {
            $middleware[] = ValidateCsrfToken::class;
        }

        return (new Pipeline(app()))
            ->send($request)
            ->through($middleware)
            ->then(fn (Request $request) => $next($request));
    }
}
