<?php

namespace Tests\Unit;

use App\Services\Nobitex\SubscriptionManager;
use PHPUnit\Framework\TestCase;

class SubscriptionManagerTest extends TestCase
{
    private SubscriptionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new SubscriptionManager;
    }

    public function test_it_registers_a_channel_subscription(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true
        );

        $subscription = $this->manager->get('public:orderbook-BTCIRT');

        $this->assertSame([
            'channel' => 'public:orderbook-BTCIRT',
            'epoch' => 'epoch-123',
            'offset' => 100,
            'recoverable' => true,
            'positioned' => true,
            'recovered' => false,
        ], $subscription);
    }

    public function test_it_returns_null_for_unknown_channel(): void
    {
        $this->assertNull(
            $this->manager->get('public:orderbook-BTCIRT')
        );
    }

    public function test_it_returns_recovery_position_for_valid_subscription(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true
        );

        $this->assertSame([
            'epoch' => 'epoch-123',
            'offset' => 100,
        ], $this->manager->getRecoveryPosition(
            'public:orderbook-BTCIRT'
        ));
    }

    public function test_it_returns_null_when_subscription_is_not_recoverable(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            false,
            true
        );

        $this->assertNull(
            $this->manager->getRecoveryPosition('public:orderbook-BTCIRT')
        );
    }

    public function test_it_returns_null_when_subscription_is_not_positioned(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            false
        );

        $this->assertNull(
            $this->manager->getRecoveryPosition('public:orderbook-BTCIRT')
        );
    }

    public function test_it_updates_offset_when_new_offset_is_greater(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true
        );

        $result = $this->manager->updateOffset(
            'public:orderbook-BTCIRT',
            105
        );

        $this->assertTrue($result);
        $this->assertSame(
            105,
            $this->manager->get('public:orderbook-BTCIRT')['offset']
        );
    }

    public function test_it_rejects_old_or_duplicate_offsets(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true
        );

        $this->assertFalse(
            $this->manager->updateOffset('public:orderbook-BTCIRT', 99)
        );

        $this->assertFalse(
            $this->manager->updateOffset('public:orderbook-BTCIRT', 100)
        );

        $this->assertSame(
            100,
            $this->manager->get('public:orderbook-BTCIRT')['offset']
        );
    }

    public function test_it_cannot_update_offset_for_unknown_channel(): void
    {
        $this->assertFalse(
            $this->manager->updateOffset('unknown-channel', 100)
        );
    }

    public function test_it_invalidates_recovery_position(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true,
            true
        );

        $this->manager->invalidate('public:orderbook-BTCIRT');

        $subscription = $this->manager->get('public:orderbook-BTCIRT');

        $this->assertNull($subscription['epoch']);
        $this->assertSame(0, $subscription['offset']);
        $this->assertFalse($subscription['positioned']);
        $this->assertFalse($subscription['recovered']);

        $this->assertNull(
            $this->manager->getRecoveryPosition('public:orderbook-BTCIRT')
        );
    }

    public function test_it_returns_all_registered_subscriptions(): void
    {
        $this->manager->register('public:orderbook-BTCIRT', 'epoch-1', 100, true, true);
        $this->manager->register('public:orderbook-ETHIRT', 'epoch-2', 200, true, true);

        $this->assertCount(2, $this->manager->all());
    }

    public function test_it_preserves_previous_offset_during_successful_recovery(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true
        );

        $this->manager->updateSubscription(
            'public:orderbook-BTCIRT',
            'epoch-123',
            120,
            true,
            true,
            true,
            true,
        );

        $this->assertSame(
            100,
            $this->manager->get('public:orderbook-BTCIRT')['offset']
        );
    }

    public function test_it_invalidates_position_when_recovery_fails(): void
    {
        $channel = 'public:orderbook-BTCIRT';

        $this->manager->register(
            $channel,
            'epoch-123',
            100,
            true,
            true
        );

        $result = $this->manager->updateSubscription(
            $channel,
            'epoch-456',
            200,
            true,
            true,
            true,
            false,
        );

        $this->assertFalse($result);

        $subscription = $this->manager->get($channel);

        $this->assertSame('epoch-456', $subscription['epoch']);
        $this->assertSame(200, $subscription['offset']);
        $this->assertFalse($subscription['positioned']);
        $this->assertFalse($subscription['recovered']);

        $this->assertNull(
            $this->manager->getRecoveryPosition($channel)
        );
    }

    public function test_it_updates_offset_after_recovered_publication_is_processed(): void
    {
        $this->manager->register(
            'public:orderbook-BTCIRT',
            'epoch-123',
            100,
            true,
            true
        );

        $this->manager->updateSubscription(
            'public:orderbook-BTCIRT',
            'epoch-123',
            120,
            true,
            true,
            true,
            true,
        );

        $this->assertTrue(
            $this->manager->updateOffset('public:orderbook-BTCIRT', 101)
        );

        $this->assertSame(
            101,
            $this->manager->get('public:orderbook-BTCIRT')['offset']
        );
    }

    public function test_it_rejects_recovery_with_different_epoch(): void
    {
        $channel = 'public:orderbook-BTCIRT';

        $this->manager->register(
            $channel,
            'epoch-123',
            100,
            true,
            true
        );

        $result = $this->manager->updateSubscription(
            $channel,
            'epoch-456',
            200,
            true,
            true,
            true,
            true,
        );

        $this->assertFalse($result);

        $this->assertNull(
            $this->manager->getRecoveryPosition($channel)
        );
    }

    public function test_it_stores_initial_subscription_position(): void
    {
        $channel = 'public:orderbook-BTCIRT';

        $this->manager->updateSubscription(
            $channel,
            'epoch-123',
            2571651,
            true,
            true,
            false,
            false
        );

        $this->assertSame(
            2571651,
            $this->manager->get($channel)['offset']
        );

        $this->assertSame(
            'epoch-123',
            $this->manager->getRecoveryPosition($channel)['epoch']
        );
    }

    public function test_it_does_not_reenable_recovery_before_resynchronization(): void
    {
        $channel = 'public:orderbook-BTCIRT';

        $this->manager->register(
            $channel,
            'epoch-123',
            100,
            true,
            true
        );

        // شکست Recovery
        $this->manager->updateSubscription(
            $channel,
            'epoch-456',
            200,
            true,
            true,
            true,
            false
        );

        // Subscribe بعدی بدون Recovery
        $this->manager->updateSubscription(
            $channel,
            'epoch-456',
            300,
            true,
            true,
            false,
            false
        );

        $this->assertFalse(
            $this->manager->get($channel)['positioned']
        );

        $this->assertNull(
            $this->manager->getRecoveryPosition($channel)
        );
    }
}
