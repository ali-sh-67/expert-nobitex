<?php

namespace App\Services\Nobitex;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

readonly class MarketMetrics
{
    public function __construct(
        public ?string $spread,
        public ?string $spreadPercentage,
    ) {
    }

    public static function fromSnapshot(
        OrderBookSnapshot $snapshot
    ): self {
        if (
            $snapshot->bestBidPrice === null ||
            $snapshot->bestAskPrice === null
        ) {
            return new self(
                spread: null,
                spreadPercentage: null,
            );
        }

        $bid = BigDecimal::of($snapshot->bestBidPrice);
        $ask = BigDecimal::of($snapshot->bestAskPrice);

        $spread = $ask->minus($bid);

        if ($bid->isZero()) {
            return new self(
                spread: $spread->toScale(0)->__toString(),
                spreadPercentage: null,
            );
        }

        $spreadPercentage = $spread
            ->dividedBy($bid, 10, RoundingMode::HalfUp)
            ->multipliedBy(100);

        return new self(
            spread: $spread->toScale(0)->__toString(),
            spreadPercentage: $spreadPercentage
                ->toScale(6, RoundingMode::HalfUp)
                ->__toString(),
        );
    }
}
