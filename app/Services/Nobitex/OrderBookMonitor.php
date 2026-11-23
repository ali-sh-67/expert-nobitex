<?php

namespace App\Services\Nobitex;

readonly class OrderBookMonitor
{
    public function __construct(
        private MarketDataManager $marketDataManager,
        private SignalManager $signalManager,
    ) {}

    public function printState(): void
    {
        $maxAge = (int) config(
            'nobitex.market.max_order_book_age_ms',
            1500
        );

        foreach ($this->marketDataManager->symbols() as $symbol) {
            $handler = $this->marketDataManager->getHandler($symbol);

            if ($handler === null) {
                continue;
            }

            $isFresh = $handler->isFresh($maxAge);
            $state = $handler->snapshot();
            $result = $this->signalManager->evaluate($symbol);
            $metrics = MarketMetrics::fromSnapshot($state);

            dump("========== {$symbol} ==========");
            dump('Data status: '.($isFresh ? 'FRESH' : 'STALE'));
            dump('Data age: '.($handler->getDataAgeMs() ?? 'N/A').' ms');
            dump('Last price: '.($state->lastTradePrice ?? 'N/A'));
            dump('Best bid: '.($state->bestBidPrice ?? 'N/A').' / '.($state->bestBidQuantity ?? 'N/A'));
            dump('Best ask: '.($state->bestAskPrice ?? 'N/A').' / '.($state->bestAskQuantity ?? 'N/A'));
            dump('Bid levels: '.$handler->getBidCount());
            dump('Ask levels: '.$handler->getAskCount());
            dump('Last update: '.($state->lastUpdate ?? 'N/A'));
            dump('Spread: '.($metrics->spread ?? 'N/A'));
            dump('Spread %: '.($metrics->spreadPercentage ?? 'N/A').'%');
            dump('Bid Depth (5): '.($state->bidDepth ?? 'N/A'));
            dump('Ask Depth (5): '.($state->askDepth ?? 'N/A'));
            dump('Order Book Imbalance: '.($metrics->imbalance ?? 'N/A'));
            dump("Signal: {$result->signal->value}");
            dump("Confidence: {$result->confidence}");
            dump("Reason: {$result->reason}");
            dump('');
        }
    }
}
