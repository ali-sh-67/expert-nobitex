<?php

namespace App\Providers;

use App\Services\Nobitex\OrderBookHandler;
use Illuminate\Support\ServiceProvider;

class NobitexServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(OrderBookHandler::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
