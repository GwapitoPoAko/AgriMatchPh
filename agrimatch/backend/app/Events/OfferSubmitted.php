<?php

namespace App\Events;

use App\Models\B2bOffer;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A buyer opened a negotiation on a listing. Dispatched only after the DB commit. */
class OfferSubmitted implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly B2bOffer $offer)
    {
    }
}
