<?php

namespace App\Console\Commands;

use App\Models\Pairs;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

#[Signature('nobitex:smp')]
#[Description('sync market pairs')]
class SyncMarketPairs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle():int
    {
        $startTime = microtime(true);
        $response = Http::timeout(20)
            ->retry(3, 500)
            ->get('https://apiv2.nobitex.ir/market/stats');

        $response->throw();

        $data = $response->json();

        if (($data['status'] ?? null) !== 'ok') {
            throw new RuntimeException('Invalid Nobitex API response.');
        }

        $stats = $data['stats'] ?? null;

        if (empty($stats) || !is_array($stats)) {
            throw new \RuntimeException('Market pairs list is empty or invalid.');
        }

        $now = now();
        $symbols = array_keys($stats);
        $dataPairs = [];

        foreach ($stats as $symbol => $option) {
            $dataPairs[] = [
                'symbol' => $symbol,
                'is_closed' => $option['isClosed'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        [$deactivePairs, $upsertCount] = DB::transaction(function () use ($symbols, $dataPairs, $now) {
        $deactivePairs = Pairs::query()
            ->whereNotIn('symbol', $symbols)
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => $now]);

        $upsertCount = Pairs::query()
            ->upsert(
                $dataPairs,
                ['symbol'],
                ['is_active', 'is_closed', 'updated_at'],
            );
            return [$deactivePairs, $upsertCount];
        });

        $this->info('Market pairs synchronized successfully.');
        $this->info('Total pairs: ' . count($dataPairs));
        $this->info('Total upsert: ' . $upsertCount);
        $this->info('Total deactivate Pairs ' . $deactivePairs);
        $this->info('Execution time: ' . (number_format((microtime(true) - $startTime), 2)). ' seconds');

        return self::SUCCESS;
    }
}
