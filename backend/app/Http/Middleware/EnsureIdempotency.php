<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes state-changing endpoints safe to replay. The PWA attaches an
 * X-Idempotency-Key (UUID) to every mutation; when a farmer drafts a listing
 * offline the request may be replayed by the outbox several times as signal
 * flickers. The first successful response is cached for 24 h and returned
 * verbatim for repeats, so a listing or offer is never created twice.
 */
class EnsureIdempotency
{
    private const TTL_SECONDS = 86400;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->headers->get('X-Idempotency-Key');

        if ($request->isMethodSafe() || ! $key) {
            return $next($request);
        }

        if (! preg_match('/^[A-Za-z0-9\-]{16,64}$/', $key)) {
            return response()->json(['message' => 'Invalid X-Idempotency-Key header.'], 400);
        }

        $owner = $request->user()?->getAuthIdentifier() ?? $request->ip();
        $cacheKey = 'idem:' . hash('sha256', $owner . '|' . $request->method() . '|' . $request->path() . '|' . $key);

        if ($cached = Cache::get($cacheKey)) {
            return response()->json($cached['body'], $cached['status'], ['Idempotent-Replayed' => 'true']);
        }

        // Concurrent duplicate (two replays in flight) — let the first one finish.
        $lock = Cache::lock($cacheKey . ':lock', 30);
        if (! $lock->get()) {
            return response()->json(['message' => 'A request with this idempotency key is already being processed.'], 409);
        }

        try {
            $response = $next($request);

            if ($response instanceof JsonResponse && $response->isSuccessful()) {
                Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }
}
