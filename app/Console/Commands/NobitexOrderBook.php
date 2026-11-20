<?php

namespace App\Console\Commands;

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
        OrderBookMonitor       $monitor,
        NobitexHttpClient      $httpClient,
        MarketDataManager      $marketDataManager,
    ): int
    {
        $symbols = $marketDataManager->symbols();

        if (empty($symbols)) {
            $this->error('No markets configured.');

            return self::FAILURE;
        }

        $channels = [];

        $orderBooks = $httpClient->getOrderBook('all');
//
//        dump($orderBooks['BTCIRT']);
//
        foreach ($symbols as $symbol) {
            if (!isset($orderBooks[$symbol])) {
                throw new \RuntimeException(
                    "Initial order book not found: {$symbol}"
                );
            }

            $marketDataManager->initialize(
                $symbol,
                $orderBooks[$symbol]
            );

            $channels[] = "public:orderbook-{$symbol}";

            $this->info("Initial snapshot loaded: {$symbol}");
        }

        run(function () use ($client, $monitor, $channels, $httpClient, $marketDataManager, $symbols) {
            go(function () use ($client, $channels) {
                $client->run($channels);
            });

            go(function () use ($monitor) {
                while (true) {
                    Coroutine::sleep(5);
                    $monitor->printState();
                }
            });

//            //یک کوروتین مستقل که هر ۳۰ ثانیه بازارهای فاقد داده تازه را بررسی می کند.
//            go(function () use ($httpClient, $marketDataManager, $symbols) {
//                $maxAge = (int)config(
//                    'nobitex.market.max_order_book_age_ms',
//                    1500
//                );
//
//                while (true) {
//                    Coroutine::sleep(7);
//
//                    foreach ($symbols as $symbol) {
//                        $handler = $marketDataManager->getHandler($symbol);
//
//                        if ($handler === null || $handler->isFresh($maxAge)) {
//                            continue;
//                        }
//
//                        try {
//                            $httpClient->refreshOrderBook(
//                                $symbol,
//                                $marketDataManager
//                            );
//
//                            Log::info('Order book REST refresh completed.', [
//                                'symbol' => $symbol,
//                                'fresh' => $handler->isFresh($maxAge),
//                            ]);
//                        } catch (Throwable $e) {
//                            Log::warning('Order book REST refresh failed.', [
//                                'symbol' => $symbol,
//                                'error' => $e->getMessage(),
//                            ]);
//                        }
//                    }
//                }
//            });
        });

        return self::SUCCESS;
    }
}
