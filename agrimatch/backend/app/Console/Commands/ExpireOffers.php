<?php

namespace App\Console\Commands;

use App\Models\B2bOffer;
use Illuminate\Console\Command;

class ExpireOffers extends Command
{
    protected $signature = 'agrimatch:expire-offers';

    protected $description = 'Mark pending offers past their expiry as expired';

    public function handle(): int
    {
        $count = B2bOffer::where('status', B2bOffer::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => B2bOffer::STATUS_EXPIRED, 'responded_at' => now(), 'updated_at' => now()]);

        $this->info("Expired {$count} offers.");

        return self::SUCCESS;
    }
}
