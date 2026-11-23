<?php

namespace App\Services\Nobitex;

readonly class MarketContext
{
    public function __construct(
        public OrderBookSnapshot $orderBook,
        public ?MarketMetrics $metrics,
    ) {}
}
