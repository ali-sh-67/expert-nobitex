<?php

namespace App\Services\Nobitex;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

readonly class MarketMetrics
{
    public function __construct(
        public ?string $spread,
        public ?string $spreadPercentage,
        public ?string $imbalance,
    ) {}

    public static function fromSnapshot(OrderBookSnapshot $snapshot): self
    {
        $spread = null;
        $spreadPercentage = null;

        if ($snapshot->bestBidPrice !== null && $snapshot->bestAskPrice !== null) {
            $bid = BigDecimal::of($snapshot->bestBidPrice);
            $ask = BigDecimal::of($snapshot->bestAskPrice);
            $spread = $ask->minus($bid);

            if ($bid->isPositive()) {
                $spreadPercentage = $spread
                    ->multipliedBy(100)
                    ->dividedBy($bid, 12, RoundingMode::HalfUp)
                    ->__toString();
            }
            $spread = $spread->__toString();
        }

        $imbalance = null;
        if ($snapshot->bidDepth !== null && $snapshot->askDepth !== null) {
            $bidDepth = BigDecimal::of($snapshot->bidDepth);
            $askDepth = BigDecimal::of($snapshot->askDepth);
            $totalDepth = $bidDepth->plus($askDepth);
            if (! $totalDepth->isZero()) {
                $imbalance = $bidDepth->minus($askDepth)->dividedBy($totalDepth, 10, RoundingMode::HalfUp)->toScale(6, RoundingMode::HalfUp)->__toString();
            }
        }

        return new self(spread: $spread, spreadPercentage: $spreadPercentage, imbalance: $imbalance);
    }
}
