<?php

namespace App\Policies;

use App\Models\B2bOffer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class B2bOfferPolicy
{
    public function before(User $user): ?bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, B2bOffer $offer): bool
    {
        return in_array($user->id, [$offer->buyer_id, $offer->seller_id], true);
    }

    public function create(User $user): Response
    {
        return $user->canBuy() ? Response::allow() : Response::deny('Only buyers and traders can make offers.');
    }

    /** Accept / reject / counter — only the party the round is waiting on. */
    public function respond(User $user, B2bOffer $offer): Response
    {
        if (! $offer->isOpen()) {
            return Response::deny('This offer is no longer open.');
        }

        return $offer->counterpartyId() === $user->id
            ? Response::allow()
            : Response::deny('Waiting on the other party to respond.');
    }

    public function withdraw(User $user, B2bOffer $offer): Response
    {
        if (! $offer->isOpen()) {
            return Response::deny('This offer is no longer open.');
        }

        return $offer->offered_by_id === $user->id
            ? Response::allow()
            : Response::deny('Only the author can withdraw an offer.');
    }
}
