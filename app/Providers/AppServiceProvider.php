<?php

namespace App\Providers;

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
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)
            ->by('register:'.$request->ip()));

        RateLimiter::for('inventory', fn (Request $request) => Limit::perMinute(60)
            ->by('inventory:'.($request->user()->id ?? $request->ip())));

        Gate::define('manage-inventory', fn (User $user) => $user->canManageInventory());
        Gate::define('manage-catalog', fn (User $user) => $user->canManageCatalog());
        Gate::define('manage-coupons', fn (User $user) => $user->isAdmin());
        Gate::define('manage-shipping', fn (User $user) => $user->isAdmin());

        RateLimiter::for('coupon-quote', fn (Request $request) => Limit::perMinute((int) config('coupons.quote_attempts_per_minute', 20))
            ->by('coupon-quote:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('coupon-admin', fn (Request $request) => Limit::perMinute(60)
            ->by('coupon-admin:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute(120)
            ->by('cart:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('shipping-admin', fn (Request $request) => Limit::perMinute(60)
            ->by('shipping-admin:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('catalog-admin', fn (Request $request) => Limit::perMinute(120)
            ->by('catalog-admin:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(20)
            ->by('uploads:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute((int) config('shop.public_requests_per_minute', 120))
            ->by('public-api:'.$request->ip()));

        RateLimiter::for('orders', fn (Request $request) => Limit::perMinute(120)
            ->by('orders:'.($request->user()->id ?? $request->ip())));

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by('checkout:'.($request->user()->id ?? $request->ip())));
    }
}
