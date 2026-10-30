<?php

namespace App\Console\Commands;

use App\Services\PriceIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RecomputePriceIndex extends Command
{
    protected $signature = 'agrimatch:price-index
        {--date= : Single date (Y-m-d), default today}
        {--from= : Range start (Y-m-d)}
        {--to= : Range end (Y-m-d)}
        {--hub= : Restrict to one trading hub id}
        {--crop= : Restrict to one crop variety id}';

    protected $description = 'Compute the daily weighted-average hub price index from verified contracts and official price feeds';

    public function handle(PriceIndexService $service): int
    {
        $from = $this->option('from') ?? $this->option('date') ?? Carbon::today()->toDateString();
        $to = $this->option('to') ?? $this->option('date') ?? $from;
        $hub = $this->option('hub') ? (int) $this->option('hub') : null;
        $crop = $this->option('crop') ? (int) $this->option('crop') : null;

        $rows = $service->recomputeRange($from, $to, $hub, $crop);
        $this->info("Hub price index: {$rows} rows written for {$from} → {$to}.");

        $alerts = $service->marketSignals(5);
        foreach ($alerts as $a) {
            $this->line(sprintf('  %-28s %-22s %s', $a['variety'], $a['hub'], $a['label']));
        }

        return self::SUCCESS;
    }
}
