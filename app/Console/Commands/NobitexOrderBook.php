<?php

namespace App\Console\Commands;

use App\Services\Nobitex\NobitexHttpClient;
use App\Services\Nobitex\NobitexWebSocketClient;
use App\Services\Nobitex\OrderBookHandler;
use App\Services\Nobitex\OrderBookMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

use Swoole\Coroutine;
use function Swoole\Coroutine\run;
use function Swoole\Coroutine\go;

#[Signature('nobitex:orderbook')]
#[Description('Listen to Nobitex BTCIRT orderbook')]
class NobitexOrderBook extends Command
{
    public function handle(
        NobitexWebSocketClient $client,
        OrderBookMonitor       $monitor,
        NobitexHttpClient      $httpClient,
        OrderBookHandler       $orderBookHandler,
    ): int
    {
        $orderBook = $httpClient->getOrderBook('BTCIRT');
        $orderBookHandler->initialize($orderBook);

        dump('/************* depth *************/');
        dump('getBidDepth: ' . $orderBookHandler->getBidDepth(5));
        dump('getAskDepth: ' . $orderBookHandler->getAskDepth(5));

        run(function () use ($client, $monitor) {

            go(function () use ($client) {
                $client->run(
                    'public:orderbook-BTCIRT'
                );
            });

            go(function () use ($monitor) {

                while (true) {
                    \Co::sleep(5);

                    $monitor->printState();
                }

            });

        });

        return self::SUCCESS;
    }
}
