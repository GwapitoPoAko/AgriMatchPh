<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureApiSession;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sanctum SPA mode: requests from SANCTUM_STATEFUL_DOMAINS get session
        // cookies + CSRF verification (X-XSRF-TOKEN). Mobile/basic clients can
        // still use Bearer tokens from POST /api/v1/auth/token.
        $middleware->statefulApi();

        // Rate limiting: routes/api.php applies `throttle:60,1` to every v1 route,
        // plus named limiters (login, offers) defined in AppServiceProvider.

        // statefulApi() adds Sanctum's stateful middleware and session handling
        // for configured SPA origins. Keep only app-specific middleware here.
        // Run the session fallback in the API group, before route-level
        // auth:sanctum middleware. Stateful SPA requests already have a session
        // from Sanctum; other requests get the local session fallback here.
        $middleware->api(prepend: [AssignRequestId::class], append: [EnsureApiSession::class]);
        $middleware->prependToPriorityList(AuthenticatesRequests::class, EnsureApiSession::class);
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureRole::class,
            'idempotent' => EnsureIdempotency::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always answer /api/* with JSON, even when the client forgets the Accept header.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Never leak stack traces / SQL to API clients outside local debugging.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password']);

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $model = $e->getPrevious() instanceof ModelNotFoundException
                    ? class_basename($e->getPrevious()->getModel())
                    : 'Resource';

                return response()->json(['message' => "{$model} not found."], 404);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $previous = $e->getPrevious();
                $message = $previous instanceof AuthorizationException ? $previous->getMessage() : $e->getMessage();

                return response()->json(['message' => $message ?: 'This action is unauthorized.'], 403);
            }
        });

        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Session expired. Refresh the CSRF cookie and try again.'], 419);
            }
        });
    })->create();
