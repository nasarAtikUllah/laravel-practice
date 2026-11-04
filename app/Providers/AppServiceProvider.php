<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('inventory', fn (Request $request) => Limit::perMinute(60)
            ->by('inventory:'.($request->user()->id ?? $request->ip())));

        Gate::define('manage-inventory', fn (User $user) => $user->canManageInventory());

        // Gate::define('manage-coupons', fn (User $user) => $user->isAdmin());
        Gate::define('manage-coupons', fn (User $user) => $user->role === UserRole::Admin);
        RateLimiter::for('coupon-quote', fn (Request $request) => Limit::perMinute((int) config('coupons.quote_attempts_per_minute', 20))
            ->by('coupon-quote:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('coupon-admin', fn (Request $request) => Limit::perMinute(60)
            ->by('coupon-admin:'.($request->user()->id ?? $request->ip())));
    }
}
