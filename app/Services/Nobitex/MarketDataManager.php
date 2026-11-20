<?php

namespace App\Services\Nobitex;

use InvalidArgumentException;

class MarketDataManager
{
    /** @var array<string, OrderBookHandler> */
    private array $handlers = [];

    public function registerMarket(string $symbol): OrderBookHandler
    {
        $symbol = $this->normalizeSymbol($symbol);

        return $this->handlers[$symbol]
            ??= new OrderBookHandler();
    }

    public function registerMarkets(array $symbols): void
    {
        foreach ($symbols as $symbol) {
            $this->registerMarket($symbol);
        }
    }

    public function getHandler(string $symbol): ?OrderBookHandler
    {
        return $this->handlers[$this->normalizeSymbol($symbol)] ?? null;
    }

    public function initialize(string $symbol, array $data): void
    {
        $this->requireHandler($symbol)->initialize($data);
    }

    public function handle(string $symbol, array $data): bool
    {
        return $this->requireHandler($symbol)->handle($data);
    }

    public function invalidateMarket(string $symbol): void
    {
        $this->requireHandler($symbol)->invalidateConnection();
    }

    public function invalidateAll(): void
    {
        foreach ($this->handlers as $handler) {
            $handler->invalidateConnection();
        }
    }

    /** @return array<string, OrderBookHandler> */
    public function all(): array
    {
        return $this->handlers;
    }

    /** @return array<int, string> */
    public function symbols(): array
    {
        return array_keys($this->handlers);
    }

    private function requireHandler(string $symbol): OrderBookHandler
    {
        $symbol = $this->normalizeSymbol($symbol);

        return $this->handlers[$symbol]
            ?? throw new InvalidArgumentException(
                "Market not registered: {$symbol}"
            );
    }

    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        if ($symbol === '' || !preg_match('/^[A-Z0-9]+$/', $symbol)) {
            throw new InvalidArgumentException('Invalid market symbol.');
        }

        return $symbol;
    }

    /**
     * تعلیق موقت یک بازار هنگام قطع اتصال
     */
    public function suspendMarket(string $symbol): void
    {
        $this->requireHandler($symbol)->suspendConnection();
    }

    /**
     * تعلیق تمام بازارها هنگام قطع WebSocket
     */
    public function suspendAll(): void
    {
        foreach ($this->handlers as $handler) {
            $handler->suspendConnection();
        }
    }

    /**
     * بازگرداندن اعتبار بازار پس از Recovery موفق
     */
    public function resumeMarket(string $symbol): bool
    {
        return $this->requireHandler($symbol)->resumeAfterRecovery();
    }
}
