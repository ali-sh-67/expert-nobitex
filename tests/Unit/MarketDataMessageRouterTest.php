<?php

namespace Tests\Unit;

use App\Services\Nobitex\MarketDataManager;
use App\Services\Nobitex\MarketDataMessageRouter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketDataMessageRouterTest extends TestCase
{
    private function makeRouter(): array
    {
        $manager = new MarketDataManager;

        $manager->registerMarkets(['BTCIRT', 'ETHIRT']);

        return [
            $manager,
            new MarketDataMessageRouter($manager),
        ];
    }

    #[Test]
    public function it_routes_messages_to_the_correct_market(): void
    {
        [$manager, $router] = $this->makeRouter();

        $result = $router->route([
            'push' => [
                'channel' => 'public:orderbook-BTCIRT',
                'pub' => [
                    'data' => [
                        'bids' => [['100', '2']],
                        'asks' => [['102', '3']],
                        'lastUpdate' => 200,
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame(
            '100',
            $manager->getHandler('BTCIRT')->getBestBidPrice()
        );
        $this->assertNull(
            $manager->getHandler('ETHIRT')->getBestBidPrice()
        );
    }

    #[Test]
    public function it_decodes_json_string_payloads(): void
    {
        [$manager, $router] = $this->makeRouter();

        $result = $router->route([
            'push' => [
                'channel' => 'public:orderbook-ETHIRT',
                'pub' => [
                    'data' => json_encode([
                        'bids' => [['200', '2']],
                        'asks' => [['202', '3']],
                        'lastUpdate' => 300,
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
        ]);

        $this->assertTrue($result);
        $this->assertSame(
            '200',
            $manager->getHandler('ETHIRT')->getBestBidPrice()
        );
    }

    #[Test]
    public function it_ignores_unregistered_markets(): void
    {
        [$manager, $router] = $this->makeRouter();

        $result = $router->route([
            'push' => [
                'channel' => 'public:orderbook-XRPIRT',
                'pub' => [
                    'data' => [],
                ],
            ],
        ]);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_ignores_malformed_payloads(): void
    {
        [, $router] = $this->makeRouter();

        $result = $router->route([
            'push' => [
                'channel' => 'public:orderbook-BTCIRT',
                'pub' => [
                    'data' => '{invalid-json',
                ],
            ],
        ]);

        $this->assertFalse($result);
    }
}
