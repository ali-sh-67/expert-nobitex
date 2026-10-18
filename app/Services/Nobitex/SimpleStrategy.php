<?php

namespace App\Services\Nobitex;

use Brick\Math\BigDecimal;

class SimpleStrategy
{
    private const IMBALANCE_THRESHOLD = '0.20';

    private const MAX_SPREAD_PERCENTAGE = '0.10';

    public function evaluate(OrderBookSnapshot $snapshot): Signal
    {
        if (
            $snapshot->bestBidPrice === null ||
            $snapshot->bestAskPrice === null
        ) {
            return Signal::HOLD;
        }

        $metrics = MarketMetrics::fromSnapshot($snapshot);

        if (
            $metrics->imbalance === null ||
            $metrics->spreadPercentage === null
        ) {
            return Signal::HOLD;
        }

        $imbalance = BigDecimal::of($metrics->imbalance);
        $spreadPercentage = BigDecimal::of(
            $metrics->spreadPercentage
        );

        if (
            $spreadPercentage->compareTo(
                BigDecimal::of(self::MAX_SPREAD_PERCENTAGE)
            ) > 0
        ) {
            return Signal::HOLD;
        }

        if (
            $imbalance->compareTo(
                BigDecimal::of(self::IMBALANCE_THRESHOLD)
            ) >= 0
        ) {
            return Signal::BUY;
        }

        if (
            $imbalance->compareTo(
                BigDecimal::of(self::IMBALANCE_THRESHOLD)->negated()
            ) <= 0
        ) {
            return Signal::SELL;
        }

        return Signal::HOLD;
    }
}
