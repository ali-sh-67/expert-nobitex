<?php

namespace App\Services\Nobitex;

readonly class OrderBookMonitor
{
    public function __construct(
        private OrderBookHandler $orderBookHandler,
        private SignalManager $signalManager,
    ) {
    }
    public function printState(): void
    {
        $isFresh = $this->orderBookHandler->isFresh(
            (int) config('nobitex.market.max_order_book_age_ms', 1500)
        );

        $state = $this->orderBookHandler->snapshot();

        $result = $this->signalManager->evaluate();


        $metrics = MarketMetrics::fromSnapshot($state);

        dump("Current OrderBook State");

        dump("Data status: " . ($isFresh ? 'FRESH' : 'STALE'));

        dump("Data age: " .
            ($this->orderBookHandler->getDataAgeMs() ?? 'N/A') . " ms");

        dump("Last price: " . ($state->lastTradePrice ?? 'N/A'));

        dump("Best bid: " . ($state->bestBidPrice ?? 'N/A')
            . " / " . ($state->bestBidQuantity ?? 'N/A'));

        dump("Best ask: " . ($state->bestAskPrice ?? 'N/A')
            . " / " . ($state->bestAskQuantity ?? 'N/A'));

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

        dump("Signal: {$result->signal->value}");
        dump("Confidence: {$result->confidence}");
        dump("Reason: {$result->reason}");
    }
}
