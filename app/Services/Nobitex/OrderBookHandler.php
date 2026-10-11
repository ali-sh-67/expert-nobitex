<?php

namespace App\Services\Nobitex;

use Brick\Math\BigDecimal;

class OrderBookHandler
{
    private ?string $lastTradePrice = null {
        get {
            return $this->lastTradePrice;
        }
    }

    private ?int $lastUpdate = null {
        get {
            return $this->lastUpdate;
        }
    }

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $bids = [];

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $asks = [];

    public function initialize(array $data): void
    {
        $this->bids = $data['bids'] ?? [];
        $this->asks = $data['asks'] ?? [];

        $this->lastTradePrice = $data['lastTradePrice'] ?? null;
        $this->lastUpdate = $data['lastUpdate'] ?? null;
    }

    /**
     * @param array{
     *     bids?: array<int, array{0: string, 1: string}>,
     *     asks?: array<int, array{0: string, 1: string}>,
     *     lastTradePrice?: string,
     *     lastUpdate?: int
     * } $data
     */
    public function handle(array $data): void
    {
        if (isset($data['bids'])) {
            $this->bids = $data['bids'];
        }

        if (isset($data['asks'])) {
            $this->asks = $data['asks'];
        }

        if (isset($data['lastTradePrice'])) {
            $this->lastTradePrice = (string)$data['lastTradePrice'];
        }

        if (isset($data['lastUpdate'])) {
            $this->lastUpdate = (int)$data['lastUpdate'];
        }
    }

    public function getBestBidPrice(): ?string
    {
        return $this->bids[0][0] ?? null;
    }

    public function getBestBidQuantity(): ?string
    {
        return $this->bids[0][1] ?? null;
    }

    public function getBestAskPrice(): ?string
    {
        return $this->asks[0][0] ?? null;
    }

    public function getBestAskQuantity(): ?string
    {
        return $this->asks[0][1] ?? null;
    }

    public function getBidDepth(int $levels): string
    {
        if ($levels <= 0) {
            return '0';
        }

        return $this->calculateDepth(
            array_slice($this->bids, 0, $levels)
        );
    }

    public function getAskDepth(int $levels): string
    {
        if ($levels <= 0) {
            return '0';
        }

        return $this->calculateDepth(
            array_slice($this->asks, 0, $levels)
        );
    }

    /**
     * @param array<int, array{0: string, 1: string}> $books
     */
    private function calculateDepth(array $books): string
    {
        $total = BigDecimal::zero();

        foreach ($books as $book) {
            $quantity = $book[1] ?? '0';

            $total = $total->plus(
                BigDecimal::of($quantity)
            );
        }

        return $total->__toString();
    }

    public function snapshot(): OrderBookSnapshot
    {
        return new OrderBookSnapshot(
            lastTradePrice: $this->lastTradePrice,
            bestBidPrice: $this->getBestBidPrice(),
            bestBidQuantity: $this->getBestBidQuantity(),
            bestAskPrice: $this->getBestAskPrice(),
            bestAskQuantity: $this->getBestAskQuantity(),
            bidDepth: $this->getBidDepth(5),
            askDepth: $this->getAskDepth(5),
            lastUpdate: $this->lastUpdate,
        );
    }

    public function getBidCount(): int
    {
        return count($this->bids);
    }

    public function getAskCount(): int
    {
        return count($this->asks);
    }
}
