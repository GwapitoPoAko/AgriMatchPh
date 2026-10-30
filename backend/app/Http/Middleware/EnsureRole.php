<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse route-level role gate: `->middleware('role:admin,coop_manager')`.
 * Fine-grained, record-level decisions live in the Policies.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'Your account role cannot perform this action.');
        }

        return $next($request);
    }
}
