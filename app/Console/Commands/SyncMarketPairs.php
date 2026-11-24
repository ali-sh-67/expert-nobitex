<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

#[Signature('nobitex:smp')]
#[Description('sync market pairs')]
class SyncMarketPairs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $response = Http::get('https://apiv2.nobitex.ir/market/stats');

        $response = $response->json();

        if(!$response['status']=='ok'){
          throw new Exception('connection is failed')
        };

        $stats = $response['stats'];

        $pairs = array_keys($stats);

        var_dump(array_first($stats)) ;

        var_dump(count($pairs));

        var_dump($status);
    }
}
