<?php

namespace Tests\Unit;

use App\Services\Nobitex\MarketDataManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketDataManagerTest extends TestCase
{
    #[Test]
    public function it_keeps_order_books_isolated_per_market(): void
    {
        $manager = new MarketDataManager();

        $manager->registerMarkets(['BTCIRT', 'ETHIRT']);

        $manager->initialize('BTCIRT', [
            'bids' => [['100', '2']],
            'asks' => [['102', '3']],
            'lastUpdate' => 200,
        ]);

        $manager->initialize('ETHIRT', [
            'bids' => [['200', '4']],
            'asks' => [['202', '5']],
            'lastUpdate' => 300,
        ]);

        $btc = $manager->getHandler('BTCIRT');
        $eth = $manager->getHandler('ETHIRT');

        $this->assertNotSame($btc, $eth);

        $this->assertSame('100', $btc->getBestBidPrice());
        $this->assertSame('200', $eth->getBestBidPrice());
    }

    #[Test]
    public function it_reuses_existing_handler_for_same_market(): void
    {
        $manager = new MarketDataManager();

        $first = $manager->registerMarket('BTCIRT');
        $second = $manager->registerMarket('btcirt');

        $this->assertSame($first, $second);
    }

    #[Test]
    public function it_rejects_unregistered_market_updates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $manager = new MarketDataManager();

        $manager->handle('BTCIRT', []);
    }

    #[Test]
    public function it_invalidates_all_registered_markets(): void
    {
        $manager = new MarketDataManager();

        $manager->registerMarkets(['BTCIRT', 'ETHIRT']);

        foreach ($manager->symbols() as $symbol) {
            $manager->initialize($symbol, [
                'bids' => [['100', '2']],
                'asks' => [['102', '3']],
                'lastUpdate' => 200,
            ]);
        }

        $manager->invalidateAll();

        foreach ($manager->all() as $handler) {
            $this->assertFalse($handler->isValid());
        }
    }

    private function snapshot(): array
    {
        return [
            'bids' => [
                ['100', '2'],
                ['99', '3'],
            ],
            'asks' => [
                ['101', '4'],
                ['102', '5'],
            ],
            'lastTradePrice' => '100.5',
            'lastUpdate' => 1000,
        ];
    }

    public function test_it_preserves_orderbook_during_connection_suspension(): void
    {
        $manager = new MarketDataManager();

        $manager->registerMarket('BTCIRT');
        $manager->initialize('BTCIRT', $this->snapshot());

        $handler = $manager->getHandler('BTCIRT');

        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());

        $manager->suspendAll();

        $this->assertFalse($handler->isValid());

        // داده قبلی نباید در زمان تعلیق قابل استفاده باشد.
        $this->assertNull($handler->getBestBidPrice());

        // پس از Recovery موفق، اطلاعات قبلی قابل استفاده می‌شوند.
        $this->assertTrue($manager->resumeMarket('BTCIRT'));

        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());
        $this->assertSame('101', $handler->getBestAskPrice());
    }

    public function test_it_cannot_resume_market_without_existing_orderbook(): void
    {
        $manager = new MarketDataManager();

        $handler = $manager->registerMarket('BTCIRT');

        $this->assertFalse($manager->resumeMarket('BTCIRT'));
        $this->assertFalse($handler->isValid());
    }
}
