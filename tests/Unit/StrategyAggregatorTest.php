<?php

namespace Tests\Unit;

use App\Services\Nobitex\MarketContext;
use App\Services\Nobitex\OrderBookSnapshot;
use App\Services\Nobitex\Signal;
use App\Services\Nobitex\StrategyAggregator;
use App\Services\Nobitex\StrategyInterface;
use App\Services\Nobitex\StrategyRegistry;
use App\Services\Nobitex\StrategyResult;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StrategyAggregatorTest extends TestCase
{
    private function makeStrategy(
        string $key,
        Signal $signal,
        float $confidence = 0.8,
    ): StrategyInterface {
        return new class(
            $key,
            new StrategyResult(
                $signal,
                $confidence,
                'test_reason',
            )
        ) implements StrategyInterface {
            public function __construct(
                private string $strategyKey,
                private StrategyResult $result,
            ) {}

            public function key(): string
            {
                return $this->strategyKey;
            }

            public function analyze(
                MarketContext $context
            ): StrategyResult {
                return $this->result;
            }
        };
    }

    private function makeContext(): MarketContext
    {
        return new MarketContext(
            new OrderBookSnapshot(
                null, null, null, null,
                null, null, null, null
            ),
            null,
        );
    }

    private function makeAggregator(
        StrategyInterface ...$strategies
    ): StrategyAggregator {
        return new StrategyAggregator(
            new StrategyRegistry($strategies)
        );
    }

    #[Test]
    public function it_executes_only_selected_strategy_in_single_mode(): void
    {
        $strategy = $this->makeStrategy('strategy_a', Signal::BUY);

        $aggregator = $this->makeAggregator(
            $strategy,
            $this->makeStrategy('strategy_b', Signal::SELL),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            ['strategy_a'],
            StrategyAggregator::MODE_SINGLE,
        );

        $this->assertSame(Signal::BUY, $result->signal);
    }

    #[Test]
    public function it_returns_buy_when_all_strategies_agree(): void
    {
        $aggregator = $this->makeAggregator(
            $this->makeStrategy('strategy_a', Signal::BUY, 0.8),
            $this->makeStrategy('strategy_b', Signal::BUY, 0.6),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            ['strategy_a', 'strategy_b'],
            StrategyAggregator::MODE_CONSENSUS,
        );

        $this->assertSame(Signal::BUY, $result->signal);
        $this->assertSame(0.6, $result->confidence);
    }

    #[Test]
    public function it_returns_hold_when_strategies_disagree(): void
    {
        $aggregator = $this->makeAggregator(
            $this->makeStrategy('strategy_a', Signal::BUY),
            $this->makeStrategy('strategy_b', Signal::SELL),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            ['strategy_a', 'strategy_b'],
            StrategyAggregator::MODE_CONSENSUS,
        );

        $this->assertSame(Signal::HOLD, $result->signal);
        $this->assertSame(
            'strategy_consensus_not_reached',
            $result->reason
        );
    }

    #[Test]
    public function it_returns_hold_when_one_strategy_returns_hold(): void
    {
        $aggregator = $this->makeAggregator(
            $this->makeStrategy('strategy_a', Signal::BUY),
            $this->makeStrategy('strategy_b', Signal::HOLD),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            ['strategy_a', 'strategy_b'],
            StrategyAggregator::MODE_CONSENSUS,
        );

        $this->assertSame(Signal::HOLD, $result->signal);
    }

    #[Test]
    public function it_returns_hold_for_unregistered_strategy(): void
    {
        $aggregator = $this->makeAggregator(
            $this->makeStrategy('strategy_a', Signal::BUY),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            ['unknown_strategy'],
        );

        $this->assertSame(Signal::HOLD, $result->signal);
        $this->assertSame(
            'strategy_not_registered',
            $result->reason
        );
    }

    #[Test]
    public function it_returns_hold_when_no_strategy_is_selected(): void
    {
        $aggregator = $this->makeAggregator(
            $this->makeStrategy('strategy_a', Signal::BUY),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            [],
        );

        $this->assertSame(Signal::HOLD, $result->signal);
        $this->assertSame(
            'no_strategies_selected',
            $result->reason
        );
    }

    #[Test]
    public function it_rejects_multiple_strategies_in_single_mode(): void
    {
        $aggregator = $this->makeAggregator(
            $this->makeStrategy('strategy_a', Signal::BUY),
            $this->makeStrategy('strategy_b', Signal::BUY),
        );

        $result = $aggregator->evaluate(
            $this->makeContext(),
            ['strategy_a', 'strategy_b'],
            StrategyAggregator::MODE_SINGLE,
        );

        $this->assertSame(Signal::HOLD, $result->signal);
        $this->assertSame(
            'single_mode_requires_one_strategy',
            $result->reason
        );
    }
}
