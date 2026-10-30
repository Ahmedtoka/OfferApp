<?php

namespace App\Providers;

use App\Shopify\GraphqlClient;
use App\Shopify\HttpGraphqlClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GraphqlClient::class, HttpGraphqlClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keyed by the verified shop (set by shopify.session, which bootstrap/app.php orders first).
        // Defensive fallback: never share one empty-key bucket if the shop is somehow missing.
        RateLimiter::for('analytics', function (Request $request) {
            $shop = $request->attributes->get('shop');

            return Limit::perMinute(10)->by(is_string($shop) && $shop !== '' ? 'shop:'.$shop : 'ip:'.$request->ip());
        });
    }
}
