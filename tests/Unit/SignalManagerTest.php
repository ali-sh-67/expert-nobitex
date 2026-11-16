<?php

namespace Tests\Unit;

use App\Services\Nobitex\OrderBookHandler;
use App\Services\Nobitex\Signal;
use App\Services\Nobitex\SignalManager;
use App\Services\Nobitex\SimpleStrategy;
use App\Services\Nobitex\StrategyAggregator;
use App\Services\Nobitex\StrategyRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignalManagerTest extends TestCase
{
    #[Test]
    public function it_returns_hold_for_invalid_order_book(): void
    {
        $handler = new OrderBookHandler();


        $manager = new SignalManager(
            $handler,
            new StrategyAggregator(
                new StrategyRegistry([
                    new SimpleStrategy(),
                ])
            )
        );

        $this->assertSame(
            Signal::HOLD,
            $manager->evaluate()->signal
        );
    }

    #[Test]
    public function it_evaluates_a_fresh_order_book(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [['100', '4']],
            'asks' => [['100.05', '1']],
            'lastTradePrice' => '100',
            'lastUpdate' => 200,
        ]);

        $manager = new SignalManager(
            $handler,
            new StrategyAggregator(
                new StrategyRegistry([
                    new SimpleStrategy(),
                ])
            )
        );


        $this->assertSame(
            Signal::BUY,
            $manager->evaluate()->signal
        );
    }
}
