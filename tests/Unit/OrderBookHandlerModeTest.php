<?php

namespace Tests\Unit;

use App\Services\Nobitex\OrderBookHandler;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use UnexpectedValueException;

class OrderBookHandlerModeTest extends TestCase
{
    #[Test]
    public function it_uses_snapshot_mode_when_configured(): void
    {
        config([
            'nobitex.orderbook_mode' => 'snapshot',
        ]);

        $handler = new OrderBookHandler;

        $result = $handler->handle([
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['102', '4'],
                ['103', '2'],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());
    }

    #[Test]
    public function it_rejects_delta_mode_until_implemented(): void
    {
        config([
            'nobitex.orderbook_mode' => 'delta',
        ]);

        $handler = new OrderBookHandler;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Delta mode is not implemented yet.');

        $handler->handle([]);
    }

    #[Test]
    public function it_rejects_invalid_orderbook_mode(): void
    {
        config([
            'nobitex.orderbook_mode' => 'invalid',
        ]);

        $handler = new OrderBookHandler;

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Invalid orderbook mode configuration.');

        $handler->handle([]);
    }
}
