<?php

namespace Tests\Unit;

use App\Console\Commands\NobitexOrderBook;
use App\Enums\OrderBookMode;
use App\Services\Nobitex\MarketDataManager;
use App\Services\Nobitex\NobitexHttpClient;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NobitexOrderBookCommandTest extends TestCase
{
    #[Test]
    public function snapshot_mode_does_not_fetch_or_initialize_rest_orderbooks(): void
    {
        $manager = new MarketDataManager;
        $manager->registerMarkets(['BTCIRT', 'ETHIRT']);

        $httpClient = $this->createMock(NobitexHttpClient::class);

        $httpClient->expects($this->never())
            ->method('getOrderBook');

        $command = new TestableNobitexOrderBook;

        $channels = $command->prepare(
            OrderBookMode::SNAPSHOT,
            ['BTCIRT', 'ETHIRT'],
            $httpClient,
            $manager
        );

        $this->assertSame([
            'public:orderbook-BTCIRT',
            'public:orderbook-ETHIRT',
        ], $channels);

        foreach ($manager->symbols() as $symbol) {
            $this->assertFalse(
                $manager->getHandler($symbol)->isValid()
            );
        }
    }

    #[Test]
    public function delta_mode_fetches_and_initializes_rest_orderbooks(): void
    {
        $manager = new MarketDataManager;
        $manager->registerMarkets(['BTCIRT']);

        $httpClient = $this->createMock(NobitexHttpClient::class);

        $httpClient->expects($this->once())
            ->method('getOrderBook')
            ->with('all')
            ->willReturn([
                'BTCIRT' => [
                    'bids' => [['100', '2']],
                    'asks' => [['101', '3']],
                    'lastUpdate' => 1000,
                ],
            ]);

        $command = new TestableNobitexOrderBook;

        $channels = $command->prepare(
            OrderBookMode::DELTA,
            ['BTCIRT'],
            $httpClient,
            $manager
        );

        $this->assertSame(
            ['public:orderbook-BTCIRT'],
            $channels
        );

        $this->assertTrue(
            $manager->getHandler('BTCIRT')->isValid()
        );

        $this->assertSame(
            '100',
            $manager->getHandler('BTCIRT')->getBestBidPrice()
        );
    }
}

class TestableNobitexOrderBook extends NobitexOrderBook
{
    public function prepare(
        OrderBookMode $mode,
        array $symbols,
        NobitexHttpClient $httpClient,
        MarketDataManager $manager,
    ): array {
        return $this->prepareMarkets(
            $mode,
            $symbols,
            $httpClient,
            $manager
        );
    }
}
