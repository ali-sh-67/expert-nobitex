<?php

namespace Tests\Unit;

use App\Services\Nobitex\MarketMetrics;
use App\Services\Nobitex\OrderBookHandler;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketMetricsTest extends TestCase
{
    #[Test]
    public function it_preserves_small_non_zero_spread_percentage(): void
    {
        $handler = new OrderBookHandler;

        $handler->initialize([
            'bids' => [['175093740620', '0.002933']],
            'asks' => [['175093740930', '0.000041']],
            'lastTradePrice' => '175093740930',
            'lastUpdate' => 200,
        ]);

        $metrics = MarketMetrics::fromSnapshot(
            $handler->snapshot()
        );

        $this->assertNotNull($metrics);

        $this->assertSame('310', (string) $metrics->spread);

        $spreadPercentage = BigDecimal::of(
            (string) $metrics->spreadPercentage
        );

        $this->assertTrue(
            $spreadPercentage->isGreaterThan(BigDecimal::zero()),
            'Spread percentage must not become zero.'
        );

        $this->assertTrue(
            $spreadPercentage->isLessThan(
                BigDecimal::of('0.000001')
            ),
            'Spread percentage is unexpectedly large.'
        );
    }
}
