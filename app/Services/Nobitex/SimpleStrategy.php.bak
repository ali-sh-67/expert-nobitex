<?php

namespace App\Services\Nobitex;

class SimpleStrategy
{
    public function evaluate(OrderBookSnapshot $snapshot): Signal
    {
        if (
            $snapshot->bestBidPrice === null ||
            $snapshot->bestAskPrice === null
        ) {
            return Signal::HOLD;
        }

        $metrics = MarketMetrics::fromSnapshot($snapshot);

        return Signal::HOLD;
    }
}
