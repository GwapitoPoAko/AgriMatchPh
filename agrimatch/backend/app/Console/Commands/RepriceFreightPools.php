<?php

namespace App\Console\Commands;

use App\Services\LogisticsPoolingService;
use Illuminate\Console\Command;

class RepriceFreightPools extends Command
{
    protected $signature = 'agrimatch:freight-reprice {--region= : Only pools whose hub is in this region code}';

    protected $description = 'Re-price open shared-freight pools with the latest fuel prices (dynamic fuel surcharge matrix)';

    public function handle(LogisticsPoolingService $logistics): int
    {
        $result = $logistics->recalculateOpenPools($this->option('region') ?: null);
        $this->info("Re-priced {$result['repriced']} open pools; surcharge changed on {$result['surcharge_changed']}.");

        return self::SUCCESS;
    }
}
