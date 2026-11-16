<?php

namespace App\Services\Nobitex;

interface StrategyInterface
{
    public function key(): string;

    public function analyze(
        MarketContext $context
    ): StrategyResult;
}
