<?php

namespace Tests\Unit;

use App\Enums\OrderBookMode;
use App\Services\Nobitex\MarketDataManager;
use App\Services\Nobitex\MarketDataMessageRouter;
use App\Services\Nobitex\NobitexWebSocketClient;
use App\Services\Nobitex\SubscriptionManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class NobitexWebSocketSnapshotTest extends TestCase
{
    private const CHANNEL = 'orderbook-BTCIRT';

    private MarketDataManager $marketDataManager;
    private MarketDataMessageRouter $router;
    private SubscriptionManager $subscriptionManager;
    private TestableSnapshotWebSocketClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nobitex.orderbook_mode' => 'snapshot']);

        $this->marketDataManager = new MarketDataManager();
        $this->router = new MarketDataMessageRouter(
            $this->marketDataManager
        );
        $this->subscriptionManager = new SubscriptionManager();

        $this->marketDataManager->registerMarket('BTCIRT');

        $this->client = new TestableSnapshotWebSocketClient(
            $this->marketDataManager,
            $this->router,
            $this->subscriptionManager
        );
    }

    #[Test]
    public function it_processes_a_valid_snapshot_without_offset(): void
    {
        $result = $this->publish($this->snapshot());

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertTrue($result);
        $this->assertTrue($handler->isValid());
        $this->assertSame('100', $handler->getBestBidPrice());
        $this->assertSame('101', $handler->getBestAskPrice());
    }

    #[Test]
    public function it_replaces_previous_orderbook_with_new_snapshot(): void
    {
        $this->publish($this->snapshot());

        $this->publish($this->snapshot(
            bids: [['110', '1'], ['109', '2']],
            asks: [['111', '3'], ['112', '4']],
            lastTradePrice: '110.5',
            lastUpdate: 1001,
        ));

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertSame('110', $handler->getBestBidPrice());
        $this->assertSame('111', $handler->getBestAskPrice());
    }

    #[Test]
    public function it_does_not_require_contiguous_offsets_for_snapshots(): void
    {
        $result = $this->publish(
            $this->snapshot(),
            offset: 9999
        );

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertTrue($result);
        $this->assertTrue($handler->isValid());
    }

    #[Test]
    public function it_rejects_invalid_snapshot(): void
    {
        $result = $this->publish(
            $this->snapshot(bids: [])
        );

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertFalse($result);
        $this->assertFalse($handler->isValid());
    }

    #[Test]
    public function it_suspends_market_after_connection_loss(): void
    {
        $this->publish($this->snapshot());

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->assertTrue($handler->isValid());

        $this->marketDataManager->suspendAll();

        $this->assertFalse($handler->isValid());

        // اطلاعات قبلی حفظ می‌شوند، اما دیگر قابل استفاده نیستند.
        $this->assertNull($handler->getBestBidPrice());
    }

    #[Test]
    public function it_does_not_activate_market_with_stale_snapshot_after_reconnect(): void
    {
        $this->publish($this->snapshot(lastUpdate: 1000));

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->marketDataManager->suspendAll();

        $result = $this->publish($this->snapshot(lastUpdate: 999));

        $this->assertTrue($result);
        $this->assertFalse($handler->isValid());
    }

    #[Test]
    public function it_activates_market_with_new_valid_snapshot_after_reconnect(): void
    {
        $this->publish($this->snapshot(lastUpdate: 1000));

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->marketDataManager->suspendAll();

        $result = $this->publish($this->snapshot(
            bids: [['110', '1'], ['109', '2']],
            asks: [['111', '3'], ['112', '4']],
            lastTradePrice: '110.5',
            lastUpdate: 1001,
        ));

        $this->assertTrue($result);
        $this->assertTrue($handler->isValid());
        $this->assertSame('110', $handler->getBestBidPrice());
        $this->assertSame('111', $handler->getBestAskPrice());
    }

    #[Test]
    public function it_keeps_market_suspended_without_a_new_snapshot(): void
    {
        $this->publish($this->snapshot());

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->marketDataManager->suspendAll();

        // بدون دریافت Snapshot جدید، بازار باید معلق بماند.
        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBestBidPrice());
    }

    #[Test]
    public function it_does_not_resume_market_from_subscribe_reply_without_snapshot(): void
    {
        $this->publish($this->snapshot(lastUpdate: 1000));

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->marketDataManager->suspendAll();

        $requestId = 123;
        $this->setPendingSubscription($requestId);

        $this->invokeSubscribeReply($requestId, [
            'subscribe' => [],
        ]);

        $this->assertFalse($handler->isValid());
        $this->assertNull($handler->getBestBidPrice());
    }

    #[Test]
    public function it_processes_snapshot_publication_from_subscribe_reply(): void
    {
        $this->publish($this->snapshot(lastUpdate: 1000));

        $handler = $this->marketDataManager->getHandler('BTCIRT');

        $this->marketDataManager->suspendAll();

        $requestId = 124;
        $this->setPendingSubscription($requestId);

        $this->invokeSubscribeReply($requestId, [
            'subscribe' => [
                'publications' => [
                    [
                        'data' => $this->snapshot(
                            bids: [['110', '1'], ['109', '2']],
                            asks: [['111', '3'], ['112', '4']],
                            lastTradePrice: '110.5',
                            lastUpdate: 1001,
                        ),
                    ],
                ],
            ],
        ]);

        $this->assertTrue($handler->isValid());
        $this->assertSame('110', $handler->getBestBidPrice());
        $this->assertSame('111', $handler->getBestAskPrice());
    }
    #[Test]
    public function it_does_not_request_recovery_in_snapshot_mode(): void
    {
        $channel = 'public:' . self::CHANNEL;

        // وجود اطلاعات Recovery قبلی را شبیه‌سازی می‌کنیم.
        $this->subscriptionManager->register(
            $channel,
            'epoch-123',
            500,
            true,
            true
        );

        $this->client->subscribe($channel);

        $this->assertCount(1, $this->client->sentPayloads);

        $this->assertSame(
            ['channel' => $channel],
            $this->client->sentPayloads[0]['subscribe']
        );
    }
    private function setPendingSubscription(int $requestId): void
    {
        $property = new \ReflectionProperty(
            NobitexWebSocketClient::class,
            'pendingSubscriptions'
        );

        $property->setValue($this->client, [
            $requestId => [
                'channel' => 'public:' . self::CHANNEL,
                'recovery_requested' => false,
            ],
        ]);
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
    private function publish(array $data, ?int $offset = null): bool
    {
        $publication = ['data' => $data];

        if ($offset !== null) {
            $publication['offset'] = $offset;
        }

        return $this->invokeHandlePublication([
            'push' => [
                'channel' => 'public:' . self::CHANNEL,
                'pub' => $publication,
            ],
        ]);
    }

    private function invokeHandlePublication(array $message): bool
    {
        $method = new \ReflectionMethod(
            NobitexWebSocketClient::class,
            'handlePublication'
        );

        return $method->invoke($this->client, $message);
    }


    private function snapshot(
        array $bids = [['100', '2'], ['99', '3']],
        array $asks = [['101', '4'], ['102', '5']],
        string $lastTradePrice = '100.5',
        int $lastUpdate = 1000,
    ): array {
        return [
            'bids' => $bids,
            'asks' => $asks,
            'lastTradePrice' => $lastTradePrice,
            'lastUpdate' => $lastUpdate,
        ];
    }
}

class TestableSnapshotWebSocketClient extends NobitexWebSocketClient
{
    public array $sentPayloads = [];

    protected function send(array $payload): void
    {
        $this->sentPayloads[] = $payload;
    }
}
