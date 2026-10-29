<?php

namespace App\Providers;

use App\Shopify\GraphqlClient;
use App\Shopify\HttpGraphqlClient;
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
        //
    }
}
