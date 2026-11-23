<?php

namespace Tests\Unit;

use App\Services\Nobitex\SimpleStrategy;
use App\Services\Nobitex\StrategyRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StrategyRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_and_retrieves_a_strategy(): void
    {
        $strategy = new SimpleStrategy;

        $registry = new StrategyRegistry([$strategy]);

        $this->assertSame(
            $strategy,
            $registry->get('simple_orderbook')
        );
    }

    #[Test]
    public function it_returns_null_for_unknown_strategy(): void
    {
        $registry = new StrategyRegistry([
            new SimpleStrategy,
        ]);

        $this->assertNull(
            $registry->get('unknown_strategy')
        );
    }

    #[Test]
    public function it_returns_all_registered_strategies(): void
    {
        $registry = new StrategyRegistry([
            new SimpleStrategy,
        ]);

        $this->assertCount(1, $registry->all());
    }

    #[Test]
    public function it_rejects_duplicate_strategy_keys(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StrategyRegistry([
            new SimpleStrategy,
            new SimpleStrategy,
        ]);
    }
}
