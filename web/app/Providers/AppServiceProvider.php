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
        RateLimiter::for('analytics', fn (Request $request) => Limit::perMinute(10)->by((string) $request->attributes->get('shop')));
    }
}
