<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Mass-assignment defense in depth: outside production, filling a
        // non-$fillable attribute throws instead of being silently dropped,
        // so a missing forceFill() is caught in development, not in an audit.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        Gate::define('manage-fuel-prices', fn (User $user) => $user->isActive() && $user->isAdmin());
        Gate::define('recompute-price-index', fn (User $user) => $user->isActive() && $user->isAdmin());
        Gate::define('view-audit-trail', fn (User $user) => $user->isActive() && $user->isAdmin());

        // Global API limit required by the platform spec: 60 requests / minute.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        // Credential stuffing guard: 5 attempts / minute per email+IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')) . '|' . $request->ip()));

        // Negotiation spam guard.
        RateLimiter::for('offers', fn (Request $request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));
    }
}
