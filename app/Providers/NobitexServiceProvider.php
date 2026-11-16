<?php

namespace App\Providers;

use App\Services\Nobitex\MarketDataManager;
use App\Services\Nobitex\MarketDataMessageRouter;
use App\Services\Nobitex\OrderBookHandler;
use App\Services\Nobitex\SimpleStrategy;
use App\Services\Nobitex\StrategyInterface;
use App\Services\Nobitex\StrategyRegistry;
use Illuminate\Support\ServiceProvider;

class NobitexServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(OrderBookHandler::class);
        $this->app->singleton(SimpleStrategy::class);

        $this->app->tag(
            [SimpleStrategy::class],
            'nobitex.strategies'
        );

        $this->app->singleton(
            StrategyRegistry::class,
            fn ($app) => new StrategyRegistry(
                $app->tagged('nobitex.strategies')
            )
        );

        $this->app->singleton(
            MarketDataManager::class,
            function ($app) {
                $manager = new MarketDataManager();

                $manager->registerMarkets(
                    config('nobitex.markets.symbols', [])
                );

                return $manager;
            }
        );

        $this->app->singleton(MarketDataMessageRouter::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
