<?php

namespace App\Services\Nobitex;

readonly class SignalManager
{
    public function __construct(
        private MarketDataManager $marketDataManager,
        private StrategyAggregator $aggregator,
    ) {}

    public function evaluate(string $symbol): StrategyResult
    {
        $handler = $this->marketDataManager->getHandler($symbol);

        if ($handler === null) {
            return new StrategyResult(
                Signal::HOLD,
                0.0,
                'market_not_registered',
            );
        }

        $maxAge = (int) config(
            'nobitex.market.max_order_book_age_ms',
            1500
        );

        if (! $handler->isFresh($maxAge)) {
            return new StrategyResult(
                Signal::HOLD,
                0.0,
                'order_book_not_fresh',
            );
        }

        $snapshot = $handler->snapshot();

        $context = new MarketContext(
            $snapshot,
            MarketMetrics::fromSnapshot($snapshot),
        );

        $strategyKeys = config(
            'nobitex.strategies.active',
            ['simple_orderbook']
        );

        $mode = config(
            'nobitex.strategies.mode',
            StrategyAggregator::MODE_SINGLE
        );

        return $this->aggregator->evaluate(
            $context,
            $strategyKeys,
            $mode,
        );
    }
}
