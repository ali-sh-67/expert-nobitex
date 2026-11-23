<?php

namespace App\Console\Commands;

use App\Enums\OrderBookMode;
use App\Services\Nobitex\MarketDataManager;
use App\Services\Nobitex\NobitexHttpClient;
use App\Services\Nobitex\NobitexWebSocketClient;
use App\Services\Nobitex\OrderBookMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Swoole\Coroutine;

use function Swoole\Coroutine\go;
use function Swoole\Coroutine\run;

#[Signature('nobitex:orderbook')]
#[Description('Listen to multiple Nobitex order books')]
class NobitexOrderBook extends Command
{
    public function handle(
        NobitexWebSocketClient $client,
        OrderBookMonitor $monitor,
        NobitexHttpClient $httpClient,
        MarketDataManager $marketDataManager,
    ): int {
        $symbols = $marketDataManager->symbols();

        if (empty($symbols)) {
            $this->error('No markets configured.');

            return self::FAILURE;
        }

        $mode = OrderBookMode::from(
            config('nobitex.orderbook_mode', 'snapshot')
        );

        $channels = $this->prepareMarkets(
            $mode,
            $symbols,
            $httpClient,
            $marketDataManager
        );

        run(function () use ($client, $monitor, $channels) {
            go(function () use ($client, $channels) {
                $client->run($channels);
            });

            go(function () use ($monitor) {
                while (true) {
                    Coroutine::sleep(5);
                    $monitor->printState();
                }
            });
        });

        return self::SUCCESS;
    }

    protected function prepareMarkets(
        OrderBookMode $mode,
        array $symbols,
        NobitexHttpClient $httpClient,
        MarketDataManager $marketDataManager,
    ): array {
        $orderBooks = [];

        if ($mode === OrderBookMode::DELTA) {
            $orderBooks = $httpClient->getOrderBook('all');
        }

        $channels = [];

        foreach ($symbols as $symbol) {
            if ($mode === OrderBookMode::DELTA) {
                if (! isset($orderBooks[$symbol])) {
                    throw new \RuntimeException(
                        "Initial order book not found: {$symbol}"
                    );
                }

                $marketDataManager->initialize(
                    $symbol,
                    $orderBooks[$symbol]
                );
            }

            $channels[] = "public:orderbook-{$symbol}";
        }

        return $channels;
    }
}
