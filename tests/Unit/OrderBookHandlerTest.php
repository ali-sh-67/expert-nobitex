<?php

namespace Tests\Unit;

use App\Services\Nobitex\MarketMetrics;
use App\Services\Nobitex\OrderBookHandler;
use App\Services\Nobitex\SimpleStrategy;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderBookHandlerTest extends TestCase
{
    #[Test]
    public function it_accepts_a_valid_order_book(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['102', '4'],
                ['103', '2'],
            ],
        ]);

        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());
        $this->assertSame('102', $handler->getBestAskPrice());
        $this->assertSame('2', $handler->getBestBidQuantity());
        $this->assertSame('4', $handler->getBestAskQuantity());
        $this->assertSame(2, $handler->getBidCount());
        $this->assertSame(2, $handler->getAskCount());
    }

    #[Test]
    public function it_rejects_bids_with_invalid_price_order(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['99', '3'],
                ['100', '2'],
            ],
            'asks' => [
                ['101', '4'],
                ['102', '2'],
            ],
        ]);

        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBestBidPrice());
        $this->assertNull($handler->getBestAskPrice());
        $this->assertNull($handler->getBidDepth(5));
        $this->assertNull($handler->getAskDepth(5));
        $this->assertSame(0, $handler->getBidCount());
        $this->assertSame(0, $handler->getAskCount());
    }

    #[Test]
    public function it_rejects_asks_with_invalid_price_order(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['103', '4'],
                ['102', '2'],
            ],
        ]);

        $this->assertFalse($handler->isValid());
    }

    #[Test]
    public function it_rejects_a_locked_or_crossed_order_book(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['101', '2'],
                ['100', '3'],
            ],
            'asks' => [
                ['101', '4'],
                ['102', '2'],
            ],
        ]);

        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBestBidPrice());
        $this->assertNull($handler->getBestAskPrice());
    }

    #[Test]
    public function it_rejects_missing_order_book_sides(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
            ],
        ]);

        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBidDepth(5));
        $this->assertNull($handler->getAskDepth(5));
    }

    #[Test]
    public function it_calculates_depth_for_valid_order_book(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['102', '4'],
                ['103', '2'],
            ],
        ]);

        $this->assertSame('5', $handler->getBidDepth(5));
        $this->assertSame('6', $handler->getAskDepth(5));
        $this->assertSame('2', $handler->getBidDepth(1));
        $this->assertSame('4', $handler->getAskDepth(1));
    }

    #[Test]
    public function it_calculates_market_metrics_for_valid_snapshot(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['102', '4'],
                ['103', '2'],
            ],
        ]);

        $snapshot = $handler->snapshot();
        $metrics = MarketMetrics::fromSnapshot($snapshot);

        $this->assertSame('2', $metrics->spread);
        $this->assertSame('2.000000', $metrics->spreadPercentage);
        $this->assertSame('-0.090909', $metrics->imbalance);
    }

    #[Test]
    public function it_returns_null_metrics_for_invalid_order_book(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['99', '3'],
                ['100', '2'],
            ],
            'asks' => [
                ['101', '4'],
                ['102', '2'],
            ],
        ]);

        $snapshot = $handler->snapshot();
        $metrics = MarketMetrics::fromSnapshot($snapshot);

        $this->assertNull($metrics->spread);
        $this->assertNull($metrics->spreadPercentage);
        $this->assertNull($metrics->imbalance);
    }

    #[Test]
    public function strategy_returns_hold_for_invalid_order_book(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['99', '3'],
                ['100', '2'],
            ],
            'asks' => [
                ['101', '4'],
                ['102', '2'],
            ],
        ]);

        $strategy = new SimpleStrategy();

        $this->assertSame(
            'HOLD',
            $strategy->evaluate($handler->snapshot())->value
        );
    }


    #[Test]
    public function it_invalidates_order_book_after_connection_loss(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['102', '4'],
                ['103', '2'],
            ],
            'lastTradePrice' => '101',
            'lastUpdate' => 123456789,
        ]);

        $this->assertTrue($handler->isValid());

        $handler->invalidateConnection();

        $this->assertFalse($handler->isValid());

        $this->assertNull($handler->getBestBidPrice());
        $this->assertNull($handler->getBestAskPrice());

        $snapshot = $handler->snapshot();

        $this->assertNull($snapshot->lastTradePrice);
        $this->assertNull($snapshot->lastUpdate);

        $this->assertNull($snapshot->bidDepth);
        $this->assertNull($snapshot->askDepth);
    }

    #[Test]
    public function it_recovers_when_a_valid_order_book_arrives_after_invalidation(): void
    {
        $handler = new OrderBookHandler();

        $handler->initialize([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['102', '4'],
                ['103', '2'],
            ],
            'lastTradePrice' => '101',
            'lastUpdate' => 123456789,
        ]);

        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());

        $handler->invalidateConnection();

        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBestBidPrice());

        $handler->handle([
            'bids' => [
                ['200', '5'],
                ['199', '2'],
            ],
            'asks' => [
                ['202', '3'],
                ['203', '4'],
            ],
            'lastTradePrice' => '201',
            'lastUpdate' => 987654321,
        ]);

        $this->assertTrue($handler->isValid());
        $this->assertSame('200', $handler->getBestBidPrice());
        $this->assertSame('202', $handler->getBestAskPrice());

        $snapshot = $handler->snapshot();

        $this->assertSame('201', $snapshot->lastTradePrice);
        $this->assertSame(987654321, $snapshot->lastUpdate);
    }
}
