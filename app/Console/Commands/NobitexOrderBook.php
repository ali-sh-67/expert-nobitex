<?php

namespace App\Console\Commands;

use App\Services\Nobitex\NobitexWebSocketClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nobitex:orderbook')]
#[Description('Listen to Nobitex BTCIRT orderbook')]
class NobitexOrderBook extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(
        NobitexWebSocketClient $client
    ): int
    {
        \Co\run(function () use ($client) {

            echo "Starting Nobitex WebSocket...\n";

            $client->connect();

            echo "Connected.\n";

            $client->subscribe('public:orderbook-BTCIRT');

            echo "Subscribed.\n";

            echo "Listening...\n";

            $client->listen();
        });

        return self::SUCCESS;
    }
}
