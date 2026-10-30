<?php

namespace App\Events;

use App\Models\B2bOffer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The counterparty accepted, rejected or countered an offer. */
class OfferResponded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly B2bOffer $offer,
        public readonly string $action,
        public readonly ?B2bOffer $counter = null,
    ) {
    }
}
