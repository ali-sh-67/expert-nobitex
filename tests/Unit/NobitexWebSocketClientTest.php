<?php

namespace Tests\Unit;

use App\Services\Nobitex\MarketDataManager;
use App\Services\Nobitex\MarketDataMessageRouter;
use App\Services\Nobitex\NobitexWebSocketClient;
use App\Services\Nobitex\SubscriptionManager;
use Tests\TestCase;
use ReflectionMethod;
use RuntimeException;

class NobitexWebSocketClientTest extends TestCase
{
    private const CHANNEL = 'public:orderbook-BTCIRT';

    private SubscriptionManager $subscriptionManager;

    private MarketDataMessageRouter $router;

    private NobitexWebSocketClient $client;

    private MarketDataManager $marketDataManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriptionManager = new SubscriptionManager();

        $this->router = $this->createMock(
            MarketDataMessageRouter::class
        );
        $this->marketDataManager = new MarketDataManager();

        $this->client = new TestableNobitexWebSocketClient(
            $this->marketDataManager,
            $this->router,
            $this->subscriptionManager
        );

        // آخرین پیام پردازش‌شده: 500
        $this->subscriptionManager->register(
            self::CHANNEL,
            'epoch-123',
            500,
            true,
            true
        );
    }

    public function test_it_ignores_duplicate_or_older_publications(): void
    {
        $this->router
            ->expects($this->never())
            ->method('route');

        // Offset برابر و کوچک‌تر از Offset فعلی
        foreach ([500, 499] as $offset) {
            $this->handlePublication($offset);
        }

        $this->assertSame(
            500,
            $this->subscriptionManager
                ->get(self::CHANNEL)['offset']
        );
    }

    public function test_it_processes_and_commits_next_publication(): void
    {
        $this->router
            ->expects($this->once())
            ->method('route')
            ->willReturn(true);

        $this->handlePublication(501);

        $this->assertSame(
            501,
            $this->subscriptionManager
                ->get(self::CHANNEL)['offset']
        );
    }

    public function test_it_detects_publication_gap_and_preserves_previous_offset(): void
    {
        $this->router
            ->expects($this->never())
            ->method('route');

        try {
            // پیام 501 دریافت نشده است.
            $this->handlePublication(502);

            $this->fail('Expected publication gap exception.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'Publication gap detected',
                $e->getMessage()
            );
        }

        // Offset سالم قبلی باید حفظ شود.
        $subscription = $this->subscriptionManager->get(self::CHANNEL);

        $this->assertSame(500, $subscription['offset']);
        $this->assertTrue($subscription['positioned']);
    }

    private function handlePublication(int $offset): void
    {
        $message = [
            'push' => [
                'channel' => self::CHANNEL,
                'pub' => [
                    'offset' => $offset,
                    'data' => [],
                ],
            ],
        ];

        $method = new ReflectionMethod(
            NobitexWebSocketClient::class,
            'handlePublication'
        );

        $method->invoke($this->client, $message);
    }


    public function test_it_stores_position_from_initial_subscribe_reply(): void
    {
        $channel = 'public:orderbook-ETHIRT';
        $requestId = 10;

        $this->setPendingSubscription($requestId, $channel, false);

        $this->invokeSubscribeReply($requestId, [
            'id' => $requestId,
            'subscribe' => [
                'recoverable' => true,
                'epoch' => 'epoch-eth',
                'offset' => 900,
                'positioned' => true,
                'was_recovering' => false,
                'recovered' => false,
                'publications' => [],
            ],
        ]);

        $this->assertSame(
            'epoch-eth',
            $this->subscriptionManager->get($channel)['epoch']
        );

        $this->assertSame(
            900,
            $this->subscriptionManager->get($channel)['offset']
        );

        $this->assertSame(
            ['epoch' => 'epoch-eth', 'offset' => 900],
            $this->subscriptionManager->getRecoveryPosition($channel)
        );
    }

    public function test_it_processes_recovered_publications_in_order(): void
    {
        $requestId = 11;

        $this->setPendingSubscription(
            $requestId,
            self::CHANNEL,
            true
        );

        $this->router
            ->expects($this->exactly(2))
            ->method('route')
            ->willReturn(true);

        $this->invokeSubscribeReply($requestId, [
            'id' => $requestId,
            'subscribe' => [
                'recoverable' => true,
                'epoch' => 'epoch-123',
                'offset' => 502,
                'positioned' => true,
                'was_recovering' => true,
                'recovered' => true,
                'publications' => [
                    [
                        'offset' => 501,
                        'data' => ['lastUpdate' => 1001],
                    ],
                    [
                        'offset' => 502,
                        'data' => ['lastUpdate' => 1002],
                    ],
                ],
            ],
        ]);

        $this->assertSame(
            502,
            $this->subscriptionManager->get(self::CHANNEL)['offset']
        );

        $this->assertTrue(
            $this->subscriptionManager->get(self::CHANNEL)['recovered']
        );
    }

    public function test_it_disables_recovery_when_subscribe_recovery_fails(): void
    {
        $requestId = 12;

        $this->setPendingSubscription(
            $requestId,
            self::CHANNEL,
            true
        );

        $this->router
            ->expects($this->never())
            ->method('route');

        $this->invokeSubscribeReply($requestId, [
            'id' => $requestId,
            'subscribe' => [
                'recoverable' => true,
                'epoch' => 'epoch-456',
                'offset' => 700,
                'positioned' => true,
                'was_recovering' => true,
                'recovered' => false,
                'publications' => [],
            ],
        ]);

        $subscription = $this->subscriptionManager->get(self::CHANNEL);

        $this->assertFalse($subscription['positioned']);
        $this->assertNull(
            $this->subscriptionManager->getRecoveryPosition(self::CHANNEL)
        );
    }

    /**
     * Helpers for testing private WebSocket methods.
     */
    private function setPendingSubscription(
        int $requestId,
        string $channel,
        bool $recoveryRequested
    ): void {
        $property = new \ReflectionProperty(
            NobitexWebSocketClient::class,
            'pendingSubscriptions'
        );

        $pending = $property->getValue($this->client);

        $pending[$requestId] = [
            'channel' => $channel,
            'recovery_requested' => $recoveryRequested,
        ];

        $property->setValue($this->client, $pending);
    }

    private function invokeSubscribeReply(
        int $requestId,
        array $response
    ): void {
        $method = new \ReflectionMethod(
            NobitexWebSocketClient::class,
            'handleSubscribeReply'
        );

        $method->invoke($this->client, $requestId, $response);
    }

    public function test_it_ignores_subscribe_reply_with_unmatched_request_id(): void
    {
        $requestId = 21;
        $channel = 'public:orderbook-ETHIRT';

        $this->setPendingSubscription($requestId, $channel, false);

        $response = json_encode([
            'id' => 22,
            'subscribe' => [
                'recoverable' => true,
                'epoch' => 'epoch-eth',
                'offset' => 900,
                'positioned' => true,
                'was_recovering' => false,
                'recovered' => false,
                'publications' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->invokeHandleResponse($response);

        $property = new \ReflectionProperty(
            NobitexWebSocketClient::class,
            'pendingSubscriptions'
        );

        $pending = $property->getValue($this->client);

        $this->assertArrayHasKey($requestId, $pending);
        $this->assertNull($this->subscriptionManager->get($channel));
    }

    public function test_it_processes_subscribe_reply_with_matching_request_id(): void
    {
        $requestId = 21;
        $channel = 'public:orderbook-ETHIRT';

        $this->setPendingSubscription($requestId, $channel, false);

        $response = json_encode([
            'id' => $requestId,
            'subscribe' => [
                'recoverable' => true,
                'epoch' => 'epoch-eth',
                'offset' => 900,
                'positioned' => true,
                'was_recovering' => false,
                'recovered' => false,
                'publications' => [],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->invokeHandleResponse($response);

        $property = new \ReflectionProperty(
            NobitexWebSocketClient::class,
            'pendingSubscriptions'
        );

        $pending = $property->getValue($this->client);

        $this->assertArrayNotHasKey($requestId, $pending);

        $this->assertSame(
            900,
            $this->subscriptionManager->get($channel)['offset']
        );
    }

    private function invokeHandleResponse(string $message): void
    {
        $method = new \ReflectionMethod(
            NobitexWebSocketClient::class,
            'handleResponse'
        );

        $method->invoke($this->client, $message);
    }

    public function test_it_sends_initial_subscribe_without_recovery(): void
    {
        $channel = 'public:orderbook-ETHIRT';

        $this->client->subscribe($channel);

        $this->assertSame([
            'id' => 2,
            'subscribe' => [
                'channel' => $channel,
            ],
        ], $this->client->sentPayloads[0]);
    }

    public function test_it_sends_recovery_position_when_resubscribing(): void
    {
        $channel = 'public:orderbook-BTCIRT';

        $this->subscriptionManager->register(
            $channel,
            'epoch-123',
            500,
            true,
            true
        );

        $this->client->subscribe($channel);

        $this->assertSame([
            'id' => 2,
            'subscribe' => [
                'channel' => $channel,
                'recover' => true,
                'epoch' => 'epoch-123',
                'offset' => 500,
            ],
        ], $this->client->sentPayloads[0]);
    }
    public function test_it_resumes_orderbook_after_recovery_without_publications(): void
    {
        $this->prepareSuspendedMarket();

        $this->router
            ->expects($this->never())
            ->method('route');

        $this->invokeRecoveryReply(31, true);

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());
        $this->assertSame('101', $handler->getBestAskPrice());
    }
    public function test_it_keeps_orderbook_suspended_after_failed_recovery(): void
    {
        $this->prepareSuspendedMarket();

        $this->router
            ->expects($this->never())
            ->method('route');

        $this->invokeRecoveryReply(32, false);

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBestBidPrice());

        $this->assertNull(
            $this->subscriptionManager->getRecoveryPosition(self::CHANNEL)
        );
    }
    private function prepareSuspendedMarket(): void
    {
        $this->marketDataManager->registerMarket('BTCIRT');

        $this->marketDataManager->initialize('BTCIRT', [
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
        ]);

        $this->marketDataManager->suspendMarket('BTCIRT');
    }

    private function invokeRecoveryReply(
        int $requestId,
        bool $recovered,
        array $publications = []
    ): void {
        $this->setPendingSubscription(
            $requestId,
            self::CHANNEL,
            true
        );

        $this->invokeSubscribeReply($requestId, [
            'id' => $requestId,
            'subscribe' => [
                'recoverable' => true,
                'epoch' => $recovered ? 'epoch-123' : 'epoch-456',
                'offset' => $recovered ? 500 : 700,
                'positioned' => true,
                'was_recovering' => true,
                'recovered' => $recovered,
                'publications' => $publications,
            ],
        ]);
    }

}

class TestableNobitexWebSocketClient extends NobitexWebSocketClient
{
    public array $sentPayloads = [];

    protected function send(array $payload): void
    {
        $this->sentPayloads[] = $payload;
    }
}
