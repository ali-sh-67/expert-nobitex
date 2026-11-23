<?php

namespace App\Services\Nobitex;

class StrategyAggregator
{
    public const string MODE_SINGLE = 'single';

    public const string MODE_CONSENSUS = 'consensus';

    public function __construct(
        private readonly StrategyRegistry $registry,
    ) {}

    public function evaluate(
        MarketContext $context,
        array $strategyKeys,
        string $mode = self::MODE_SINGLE,
    ): StrategyResult {
        $strategyKeys = array_values(array_unique($strategyKeys));

        if ($strategyKeys === []) {
            return $this->hold('no_strategies_selected');
        }

        if (! in_array($mode, [
            self::MODE_SINGLE,
            self::MODE_CONSENSUS,
        ], true)) {
            return $this->hold('unsupported_aggregation_mode');
        }

        if ($mode === self::MODE_SINGLE && count($strategyKeys) !== 1) {
            return $this->hold('single_mode_requires_one_strategy');
        }

        $results = [];

        foreach ($strategyKeys as $key) {
            $strategy = $this->registry->get($key);

            if ($strategy === null) {
                return $this->hold(
                    'strategy_not_registered',
                    ['strategy' => $key]
                );
            }

            $results[$key] = $strategy->analyze($context);
        }

        if ($mode === self::MODE_SINGLE) {
            return reset($results);
        }

        $signals = array_unique(
            array_map(
                fn (StrategyResult $result) => $result->signal->value,
                $results
            )
        );

        if (
            count($signals) !== 1 ||
            reset($signals) === Signal::HOLD->value
        ) {
            return $this->hold(
                'strategy_consensus_not_reached',
                ['strategies' => $this->summarize($results)]
            );
        }

        $confidence = min(
            array_map(
                fn (StrategyResult $result) => $result->confidence,
                $results
            )
        );

        $signal = reset($results)->signal;

        return new StrategyResult(
            $signal,
            $confidence,
            'strategy_consensus_'.strtolower($signal->value),
            ['strategies' => $this->summarize($results)]
        );
    }

    private function hold(
        string $reason,
        array $metadata = [],
    ): StrategyResult {
        return new StrategyResult(
            Signal::HOLD,
            0.0,
            $reason,
            $metadata,
        );
    }

    private function summarize(array $results): array
    {
        $summary = [];

        foreach ($results as $key => $result) {
            $summary[$key] = [
                'signal' => $result->signal->value,
                'confidence' => $result->confidence,
                'reason' => $result->reason,
            ];
        }

        return $summary;
    }
}
