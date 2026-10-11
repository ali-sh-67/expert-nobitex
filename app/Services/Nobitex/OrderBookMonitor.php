<?php

namespace App\Services\Nobitex;

readonly class OrderBookMonitor
{
    public function __construct(
        private OrderBookHandler $orderBookHandler,
        private SimpleStrategy   $strategy,
    )
    {
    }

    public function printState(): void
    {
        $state = $this->orderBookHandler->snapshot();

        $signal = $this->strategy->evaluate($state);

        $metrics = MarketMetrics::fromSnapshot($state);

        dump("Current OrderBook State");

        dump("Last price: " . ($state->lastTradePrice ?? 'N/A'));

        dump("Best bid: " . ($state->bestBidPrice ?? 'N/A') . " / " . ($state->bestBidQuantity ?? 'N/A'));

        dump("Best ask: " . ($state->bestAskPrice ?? 'N/A') . " / " . ($state->bestAskQuantity ?? 'N/A'));

        dump("Bid levels: " . $this->orderBookHandler->getBidCount());

        dump("Ask levels: " . $this->orderBookHandler->getAskCount());

        dump("Last update: " . ($state->lastUpdate ?? 'N/A'));

        dump("Spread: " . ($metrics->spread ?? 'N/A'));

        dump("Spread %: " . ($metrics->spreadPercentage ?? 'N/A') . "%");


        echo "Bid Depth (5): "
            . ($state->bidDepth ?? 'N/A')
            . PHP_EOL;

        echo "Ask Depth (5): "
            . ($state->askDepth ?? 'N/A')
            . PHP_EOL;

        echo "Order Book Imbalance: "
            . ($metrics->imbalance ?? 'N/A')
            . PHP_EOL;


        dump("Signal: {$signal->value}");
    }
}
