<?php

namespace App\Services\Nobitex;

use Brick\Math\BigDecimal;

class SimpleStrategy implements StrategyInterface
{
    private const string IMBALANCE_THRESHOLD = '0.20';

    private const string MAX_SPREAD_PERCENTAGE = '0.10';

    public function key(): string
    {
        return 'simple_orderbook';
    }

    public function analyze(MarketContext $context): StrategyResult
    {
        $metrics = $context->metrics;

        if (
            $metrics === null ||
            $metrics->spreadPercentage === null ||
            $metrics->imbalance === null
        ) {
            return new StrategyResult(
                Signal::HOLD,
                0.0,
                'market_metrics_unavailable',
            );
        }

        $spread = BigDecimal::of((string) $metrics->spreadPercentage);
        $imbalance = BigDecimal::of((string) $metrics->imbalance);

        if (
            $spread->isGreaterThan(
                BigDecimal::of(self::MAX_SPREAD_PERCENTAGE)
            )
        ) {
            return new StrategyResult(
                Signal::HOLD,
                0.0,
                'spread_above_limit',
                [
                    'spread_percentage' => (string) $spread,
                    'imbalance' => (string) $imbalance,
                ],
            );
        }

        $confidence = min(1.0, abs((float) (string) $imbalance));

        if (
            $imbalance->isGreaterThan(
                BigDecimal::of(self::IMBALANCE_THRESHOLD)
            )
        ) {
            return new StrategyResult(
                Signal::BUY,
                $confidence,
                'buy_imbalance_threshold_reached',
                ['imbalance' => (string) $imbalance],
            );
        }

        if (
            $imbalance->isLessThan(
                BigDecimal::of('-'.self::IMBALANCE_THRESHOLD)
            )
        ) {
            return new StrategyResult(
                Signal::SELL,
                $confidence,
                'sell_imbalance_threshold_reached',
                ['imbalance' => (string) $imbalance],
            );
        }

        return new StrategyResult(
            Signal::HOLD,
            0.0,
            'no_signal_threshold_reached',
            ['imbalance' => (string) $imbalance],
        );
    }
}
