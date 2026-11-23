<?php

namespace App\Services\Nobitex;

readonly class OrderBookSnapshot
{
    public function __construct(
        public ?string $lastTradePrice,
        public ?string $bestBidPrice,
        public ?string $bestBidQuantity,
        public ?string $bestAskPrice,
        public ?string $bestAskQuantity,
        public ?string $bidDepth,
        public ?string $askDepth,
        public ?int $lastUpdate,
    ) {}
}
